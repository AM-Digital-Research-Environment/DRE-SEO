<?php
declare(strict_types=1);

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/CitationKindMap.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/CitationData.php';
require_once __DIR__ . '/../src/Service/CitationMeta.php';

function citationMetaService(): CitationMeta
{
    $config = require __DIR__ . '/../config/module.config.php';
    $citation = $config['dre_seo']['citation'];
    $kinds = new CitationKindMap($citation['template_kinds'], $citation['default_kind']);

    return new CitationMeta($kinds, new CitationData($kinds));
}

/**
 * @param array<int,array{0:string,1:string}> $tags
 * @return string[] every content emitted under $name
 */
function tagValues(array $tags, string $name): array
{
    return array_values(array_map(
        static fn (array $tag): string => $tag[1],
        array_filter($tags, static fn (array $tag): bool => $tag[0] === $name)
    ));
}

test('a thesis tells Zotero and Scholar what it is and who awarded it', function (): void {
    $thesis = new ItemRepresentation('Das Archiv als Heimat', 16, 'Doctoral thesis', [
        'dcterms:title' => [new ValueRepresentation('Das Archiv als Heimat : Die Fantasie Afrika')],
        'bibo:authorList' => [new ValueRepresentation('', new ItemRepresentation('Greven, Katharina', 4))],
        'dcterms:date' => [new ValueRepresentation('2021', null, null, '2021')],
        'marcrel:dgg' => [new ValueRepresentation('Universität Bayreuth')],
    ], null, 41980, 'fabio:DoctoralThesis');

    $tags = citationMetaService()->tags($thesis, 'https://example.test/s/amira/item/41980');

    assertSameValue(['Das Archiv als Heimat: Die Fantasie Afrika'], tagValues($tags, 'citation_title'));
    assertSameValue(['Greven, Katharina'], tagValues($tags, 'citation_author'));
    assertSameValue(['Universität Bayreuth'], tagValues($tags, 'citation_dissertation_institution'));
    assertSameValue(['2021'], tagValues($tags, 'citation_publication_date'));
    // The exact Zotero type, once — never alongside the class label.
    assertSameValue(['thesis'], tagValues($tags, 'DC.type'));
});

test('Scholar dates are slash-separated and as precise as the record', function (): void {
    $article = new ItemRepresentation('An article', 11, 'Journal Article', [
        'dcterms:date' => [new ValueRepresentation('2025-03-20', null, null, '2025-03-20')],
        'dcterms:isPartOf' => [new ValueRepresentation('Pambazuka News')],
        'bibo:isbn13' => [new ValueRepresentation('978-3-96091-668-0')],
    ]);

    $tags = citationMetaService()->tags($article, null);

    assertSameValue(['2025/03/20'], tagValues($tags, 'citation_publication_date'));
    assertSameValue(['Pambazuka News'], tagValues($tags, 'citation_journal_title'));
    // An article keeps its class label: citation_journal_title already types it.
    assertSameValue(['Journal Article'], tagValues($tags, 'DC.type'));
});

test('a descriptive record carries Dublin Core only', function (): void {
    foreach ([4 => 'Person', 6 => 'Authority file', 23 => 'Journal'] as $templateId => $class) {
        $record = new ItemRepresentation('Aas, Norbert', $templateId, $class, [
            'dcterms:title' => [new ValueRepresentation('Aas, Norbert')],
        ]);
        $tags = citationMetaService()->tags($record, 'https://example.test/s/amira/item/778');
        $names = array_unique(array_column($tags, 0));

        assertTrue(
            array_filter($names, static fn (string $name): bool => str_starts_with($name, 'citation_')) === [],
            "template {$templateId} must emit no Highwire tag"
        );
        assertSameValue([$class], tagValues($tags, 'DC.type'), "template {$templateId}");
    }
});

test('item-set and media pages carry no Highwire tags', function (): void {
    // Not an ItemRepresentation: an item set or a media record.
    $itemSet = new AbstractResourceEntityRepresentation('Publications', null, null, [
        'dcterms:title' => [new ValueRepresentation('Publications')],
    ]);
    $tags = citationMetaService()->tags($itemSet, 'https://example.test/s/amira/item-set/29918');

    assertSameValue([], tagValues($tags, 'citation_title'));
    assertSameValue([], tagValues($tags, 'citation_public_url'));
    assertSameValue(['Publications'], tagValues($tags, 'DC.title'));
});

test('a podcast credits its host as its author and names its Zotero type', function (): void {
    $podcast = new ItemRepresentation('Episode', 21, 'Audio document', [
        'dcterms:title' => [new ValueRepresentation('Episode')],
        'marcrel:hst' => [new ValueRepresentation('Akese, Grace Abena')],
        'marcrel:spk' => [new ValueRepresentation('Raghuram, Parvati')],
    ]);

    $tags = citationMetaService()->tags($podcast, null);

    assertSameValue(['Akese, Grace Abena'], tagValues($tags, 'citation_author'), 'the host, as on the record page');
    assertSameValue(['podcast'], tagValues($tags, 'DC.type'));
});

test('DC.identifier is the persistent identifier when there is one, else the page', function (): void {
    $book = new ItemRepresentation('A book', 15, 'Book', [
        'dcterms:title' => [new ValueRepresentation('A book')],
        'dcterms:creator' => [new ValueRepresentation('Example, Ada')],
        'bibo:doi' => [new ValueRepresentation('10.1234/abcd')],
    ], null, 77);

    $page = 'https://example.test/s/amira/item/77';
    $withUri = citationMetaService()->tags($book, $page, 'https://example.test/resource/77');
    $withoutUri = citationMetaService()->tags($book, $page);

    assertSameValue(['https://example.test/resource/77'], tagValues($withUri, 'DC.identifier'));
    assertSameValue([$page], tagValues($withUri, 'citation_public_url'), 'the landing page stays the page');
    assertSameValue([$page], tagValues($withoutUri, 'DC.identifier'));
    assertSameValue(['10.1234/abcd'], tagValues($withUri, 'citation_doi'));
});
