<?php
declare(strict_types=1);

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKind;
use DRESeo\Service\CitationKindMap;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/CitationKindMap.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/CitationData.php';

/** The production template→kind map, so the tests bind to the shipped config. */
function citationDataService(): CitationData
{
    $config = require __DIR__ . '/../config/module.config.php';
    $citation = $config['dre_seo']['citation'];

    return new CitationData(new CitationKindMap($citation['template_kinds'], $citation['default_kind']));
}

function literalValue(string $text, ?string $raw = null): ValueRepresentation
{
    return new ValueRepresentation($text, null, null, $raw);
}

function linkedValue(string $title, int $templateId = 4): ValueRepresentation
{
    return new ValueRepresentation('', new ItemRepresentation($title, $templateId));
}

test('a journal article maps every publication field', function (): void {
    $item = new ItemRepresentation('Muslim Politics', 11, 'Journal Article', [
        'dcterms:title'    => [literalValue('Muslim Politics')],
        'dcterms:creator'  => [linkedValue('Jean-Louis Triaud')],
        'bibo:editorList'  => [linkedValue('David Robinson')],
        'dcterms:issued'   => [literalValue('7 December 2018', '2018-12-07')],
        'dcterms:isPartOf' => [linkedValue('Journal of African History', 23)],
        'dcterms:publisher' => [literalValue('Brill')],
        'bibo:volume'      => [literalValue('42')],
        'bibo:issue'       => [literalValue('3')],
        'bibo:pageStart'   => [literalValue('185')],
        'bibo:pageEnd'     => [literalValue('209')],
        'bibo:issn'        => [literalValue('1234-5678')],
        'bibo:doi'         => [literalValue('https://doi.org/10.1234/abcd')],
        'dcterms:language' => [linkedValue('English', 6)],
        'bibo:abstract'    => [literalValue('An abstract.')],
        'dcterms:subject'  => [linkedValue('Islam', 6), linkedValue('Benin', 6)],
        'dre:id'           => [literalValue('abz-01-0000')],
    ], null, 42);

    $record = citationDataService()->build($item, 'https://example.test/s/amira/item/42');

    assertTrue($record !== null, 'a journal article is citable');
    assertSameValue(CitationKind::Article, $record->kind);
    assertSameValue('Muslim Politics', $record->title);
    assertSameValue('Triaud', $record->authors[0]->family);
    assertSameValue('Robinson', $record->editors[0]->family);
    // isPartOf is the container; publisher stays its own field.
    assertSameValue('Journal of African History', $record->container);
    assertSameValue('Brill', $record->publisher);
    assertSameValue('42', $record->volume);
    assertSameValue('1234-5678', $record->issn);
    // The DOI is normalised down to the bare identifier.
    assertSameValue('10.1234/abcd', $record->doi);
    assertSameValue('English', $record->language);
    assertSameValue(['Islam', 'Benin'], $record->keywords);
    assertSameValue('abz-01-0000', $record->accession);
    assertSameValue('https://example.test/s/amira/item/42', $record->url);
    // The raw stored timestamp is preferred, so month and day survive.
    assertSameValue(2018, $record->issued->year);
    assertSameValue(12, $record->issued->month);
    assertSameValue(7, $record->issued->day);
});

test('a chapter reads its book title from isPartOf, not the container slot', function (): void {
    $item = new ItemRepresentation('The Politics of Piety', 14, null, [
        'dcterms:title'    => [literalValue('The Politics of Piety')],
        'dcterms:isPartOf' => [linkedValue('Muslim Societies', 15)],
        'dcterms:publisher' => [literalValue('Brill')],
    ], null, 7);

    $record = citationDataService()->build($item);

    assertSameValue(CitationKind::Chapter, $record->kind);
    assertSameValue('Muslim Societies', $record->bookTitle);
    assertSameValue(null, $record->container, 'a chapter has no separate container');
    assertSameValue('Brill', $record->publisher);
});

test('authority records are not citable', function (): void {
    $data = citationDataService();

    foreach ([2 => 'organisation', 3 => 'location', 4 => 'person', 5 => 'project', 7 => 'section'] as $templateId => $label) {
        $item = new ItemRepresentation('An ' . $label, $templateId, null, [
            'dcterms:title' => [literalValue('An ' . $label)],
        ]);
        assertTrue(!$data->isCitable($templateId), "{$label} must not be citable");
        assertSameValue(null, $data->build($item), "{$label} must yield no record");
    }
});

test('an item with no template falls back to the default kind and still cites', function (): void {
    $item = new ItemRepresentation('An untemplated record', null, null, [
        'dcterms:title' => [literalValue('An untemplated record')],
    ]);

    $record = citationDataService()->build($item);

    assertTrue($record !== null, 'no template still yields a citation');
    assertSameValue(CitationKind::Item, $record->kind);
});

test('authors fall through the role properties in order', function (): void {
    $data = citationDataService();

    // bibo:authorList wins over dcterms:creator…
    $both = new ItemRepresentation('A', 11, null, [
        'bibo:authorList' => [linkedValue('Ada Lovelace')],
        'dcterms:creator' => [linkedValue('Someone Else')],
    ]);
    assertSameValue('Lovelace', $data->build($both)->authors[0]->family);

    // …and marcrel:aut is the last resort for a record that uses only it.
    $marcrelOnly = new ItemRepresentation('A', 11, null, [
        'marcrel:aut' => [linkedValue('Ada Lovelace')],
    ]);
    assertSameValue('Lovelace', $data->build($marcrelOnly)->authors[0]->family);
});

test('podcasts credit hosts and videos credit speakers', function (): void {
    $data = citationDataService();

    $podcast = new ItemRepresentation('Episode 4', 21, null, [
        'dcterms:title' => [literalValue('Episode 4')],
        'marcrel:hst'   => [linkedValue('Ada Lovelace')],
        'marcrel:spk'   => [linkedValue('A Guest')],
    ]);
    $record = $data->build($podcast);
    assertSameValue(CitationKind::Podcast, $record->kind);
    // The host role is read first, and wins outright — it is the podcaster.
    assertSameValue('Lovelace', $record->authors[0]->family);
    assertSameValue(1, count($record->authors));

    $video = new ItemRepresentation('Opening lecture', 22, null, [
        'dcterms:title' => [literalValue('Opening lecture')],
        'marcrel:spk'   => [linkedValue('Ada Lovelace')],
    ]);
    $videoRecord = $data->build($video);
    assertSameValue(CitationKind::Video, $videoRecord->kind);
    assertSameValue('Lovelace', $videoRecord->authors[0]->family);
});

test('a creator linked to an organisation record is kept as one field', function (): void {
    $item = new ItemRepresentation('Annual report', 12, null, [
        'dcterms:title'   => [literalValue('Annual report')],
        'dcterms:creator' => [linkedValue('Africa Multiple Cluster of Excellence', 2)],
    ]);

    $creator = citationDataService()->build($item)->authors[0];

    assertTrue($creator->isInstitution, 'a linked Organisation is an institution');
    assertSameValue('Africa Multiple Cluster of Excellence', $creator->literal);
    assertSameValue(null, $creator->family, 'an institution is never split');
});

test('a record with no creators or date still builds', function (): void {
    $item = new ItemRepresentation('A bare record', 10, null, [
        'dcterms:title' => [literalValue('A bare record')],
    ]);

    $record = citationDataService()->build($item);

    assertSameValue('A bare record', $record->title);
    assertSameValue([], $record->authors);
    assertTrue(!$record->issued->hasYear());
});

test('a record with no dcterms:title falls back to its display title', function (): void {
    $item = new ItemRepresentation('Display title', 10);

    assertSameValue('Display title', citationDataService()->build($item)->title);
});

test('the date terms are tried in issued, date, created order', function (): void {
    $data = citationDataService();

    $dateOnly = new ItemRepresentation('A', 11, null, ['dcterms:date' => [literalValue('2001', '2001')]]);
    assertSameValue(2001, $data->build($dateOnly)->issued->year);

    $both = new ItemRepresentation('A', 11, null, [
        'dcterms:issued'  => [literalValue('2018', '2018')],
        'dcterms:date'    => [literalValue('2001', '2001')],
        'dcterms:created' => [literalValue('1999', '1999')],
    ]);
    assertSameValue(2018, $data->build($both)->issued->year, 'dcterms:issued wins');
});

test('the accession id prefers dre:id and falls back to a publication id', function (): void {
    $data = citationDataService();

    // A research item carries dre:id, plus free-text identifiers that would make
    // a nonsense cite key — dre:id must win outright.
    $researchItem = new ItemRepresentation('A record', 10, null, [
        'dre:id' => [literalValue('abg-99-0000')],
        'dcterms:identifier' => [
            literalValue('Volume 8: Yoruba Architecture and Wall Painting'),
            literalValue('KG_00001_NachlassUB'),
        ],
    ]);
    assertSameValue('abg-99-0000', $data->build($researchItem)->accession);

    // A publication has no dre:id; its ERef/EPub id is the usable one.
    foreach (['eref-95983', 'epub-1234'] as $publicationId) {
        $publication = new ItemRepresentation('An article', 11, null, [
            'dcterms:identifier' => [literalValue($publicationId)],
        ]);
        assertSameValue($publicationId, $data->build($publication)->accession);
    }

    // Free text alone yields nothing, so the cite key falls back to item-<id>.
    $freeText = new ItemRepresentation('A record', 10, null, [
        'dcterms:identifier' => [literalValue('Some descriptive shelf mark')],
    ]);
    assertSameValue(null, $data->build($freeText)->accession);
});

test('a bare DOI and a doi: prefix both normalise', function (): void {
    $data = citationDataService();

    foreach ([
        '10.1234/abcd',
        'doi:10.1234/abcd',
        'https://doi.org/10.1234/abcd',
        'http://dx.doi.org/10.1234/abcd',
    ] as $stored) {
        $item = new ItemRepresentation('A', 11, null, ['bibo:doi' => [literalValue($stored)]]);
        assertSameValue('10.1234/abcd', $data->build($item)->doi, "failed for {$stored}");
    }

    // A URI-typed DOI carrying no label still yields the identifier.
    $uriItem = new ItemRepresentation('A', 11, null, [
        'bibo:doi' => [new ValueRepresentation('', null, 'https://doi.org/10.1234/abcd')],
    ]);
    assertSameValue('10.1234/abcd', $data->build($uriItem)->doi);
});
