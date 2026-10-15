<?php
declare(strict_types=1);

use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;

require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/StructuredData.php';

test('YouTube resources emit current VideoObject fields', function (): void {
    $speaker = new Resource('Ada Example');
    $language = new Resource('English');
    $playlist = new Resource(
        'Research stories',
        publicUrl: 'https://example.test/s/amira/item/99'
    );
    $video = new Resource(
        'A research video',
        22,
        'Audio Visual Document',
        [
            'dcterms:abstract' => [new Value('A concise video description.')],
            'dcterms:date' => [new Value('2026-07-20')],
            'marcrel:spk' => [new Value(resource: $speaker)],
            'dcterms:language' => [new Value(resource: $language)],
            'dcterms:isPartOf' => [new Value(resource: $playlist)],
            'fabio:hasURL' => [new Value(uriValue: 'https://www.youtube.com/watch?v=abc123')],
        ]
    );
    $builder = new StructuredData([22 => 'VideoObject']);

    $data = $builder->forResource(
        $video,
        new SiteRepresentation(),
        'https://example.test/s/amira/item/22',
        'https://i.ytimg.com/vi/abc123/hqdefault.jpg'
    );

    assertSameValue('VideoObject', $data['@type']);
    assertSameValue('2026-07-20', $data['uploadDate']);
    assertSameValue('https://i.ytimg.com/vi/abc123/hqdefault.jpg', $data['thumbnailUrl']);
    assertSameValue(['https://www.youtube.com/watch?v=abc123'], $data['sameAs']);
    assertSameValue('Ada Example', $data['author'][0]['name']);
    assertSameValue('English', $data['inLanguage']);
    assertSameValue('Research stories', $data['isPartOf']['name']);
});

test('Person sameAs comes from the RDSpace handle, never a WissKI URL', function (): void {
    // WissKI was retired in October 2026 and its dre:wisskiUrl values are being
    // cleared; one still on a record must not resurface as an identity link.
    $person = new Resource(
        'Example, Ada',
        4,
        null,
        [
            'dre:wisskiUrl' => [new Value(uriValue: 'https://wisski.example.test/wisski/navigate/1/view')],
            'dre:rdspaceHandle' => [new Value('10962/12345')],
        ]
    );
    $builder = new StructuredData([4 => 'Person']);

    $data = $builder->forResource($person, new SiteRepresentation(), null, null);

    assertSameValue('Person', $data['@type']);
    assertSameValue(['https://hdl.handle.net/10962/12345'], $data['sameAs']);
});

test('a person is named in natural order and linked to its authority files', function (): void {
    $person = new Resource('Drescher, Martina', 4, 'Person', [
        'dcterms:identifier' => [new Value('GND 124120881', uriValue: 'https://d-nb.info/gnd/124120881')],
    ]);
    $builder = new StructuredData([4 => 'Person']);

    $data = $builder->forResource($person, new SiteRepresentation(), 'https://example.test/s/amira/item/46', null);

    assertSameValue('Martina Drescher', $data['name']);
    assertSameValue('Martina', $data['givenName']);
    assertSameValue('Drescher', $data['familyName']);
    assertSameValue('Drescher, Martina', $data['alternateName']);
    assertSameValue(['https://d-nb.info/gnd/124120881'], $data['sameAs']);
    assertTrue(!isset($data['image']), 'no image is claimed for a record that has none');
});

test('a subject heading is a DefinedTerm in its vocabulary', function (): void {
    $term = new Resource('Gender', 6, 'Authority file', [
        'dcterms:type' => [new Value(resource: new Resource('Library of Congress Subject Headings', 6))],
        'dcterms:identifier' => [
            new Value('sh00002547', uriValue: 'http://id.loc.gov/authorities/subjects/sh00002547'),
            new Value('Q162378', uriValue: 'http://www.wikidata.org/entity/Q162378'),
        ],
        // Creative-work properties must not leak onto a term.
        'dcterms:subject' => [new Value('Something')],
    ]);
    $config = require __DIR__ . '/../config/module.config.php';
    $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);

    $data = $builder->forResource($term, new SiteRepresentation(), null, null);

    assertSameValue('DefinedTerm', $data['@type']);
    assertSameValue('Library of Congress Subject Headings', $data['inDefinedTermSet']['name']);
    assertSameValue(2, count($data['sameAs']));
    assertTrue(!isset($data['keywords']), 'a term has no keywords of its own');
});

test('a journal is a Periodical, and a bare Wikidata id becomes its entity URI', function (): void {
    $journal = new Resource('Zeitschrift für französische Sprache und Literatur', 23, 'Journal', [
        'bibo:issn' => [new Value('2366-2425')],
        'dcterms:identifier' => [new Value('Q15755461', uriValue: 'Q15755461')],
    ]);
    $config = require __DIR__ . '/../config/module.config.php';
    $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);

    $data = $builder->forResource($journal, new SiteRepresentation(), null, null);

    assertSameValue('Periodical', $data['@type']);
    assertSameValue('2366-2425', $data['issn']);
    assertSameValue(['http://www.wikidata.org/entity/Q15755461'], $data['sameAs']);
});

test('a place has numeric coordinates and the place that contains it', function (): void {
    $place = new Resource('Sankuru', 3, 'Location', [
        'geo:lat' => [new Value('-3.3575')],
        'geo:long' => [new Value('23.59666667')],
        'dcterms:isPartOf' => [new Value(resource: new Resource('Democratic Republic of the Congo', 3))],
    ]);
    $builder = new StructuredData([3 => 'Place']);

    $data = $builder->forResource($place, new SiteRepresentation(), null, null);

    assertSameValue(-3.3575, $data['geo']['latitude']);
    assertSameValue('Democratic Republic of the Congo', $data['containedInPlace']['name']);
});

test('an article sits in its periodical, volume and issue, with its DOI', function (): void {
    $article = new Resource('Muslim Politics', 11, 'Journal Article', [
        'bibo:authorList' => [new Value(resource: new Resource('Triaud, Jean-Louis', 4))],
        'dcterms:isPartOf' => [new Value('Journal of African History')],
        'bibo:volume' => [new Value('42')],
        'bibo:issue' => [new Value('3')],
        'bibo:issn' => [new Value('0021-8537')],
        'bibo:pageStart' => [new Value('185')],
        'bibo:doi' => [new Value('10.1234/abcd')],
    ]);
    $builder = new StructuredData([11 => 'ScholarlyArticle']);

    $data = $builder->forResource($article, new SiteRepresentation(), null, null);

    assertSameValue('Jean-Louis Triaud', $data['author'][0]['name']);
    assertSameValue('PublicationIssue', $data['isPartOf']['@type']);
    assertSameValue('3', $data['isPartOf']['issueNumber']);
    assertSameValue('42', $data['isPartOf']['isPartOf']['volumeNumber']);
    assertSameValue('0021-8537', $data['isPartOf']['isPartOf']['isPartOf']['issn']);
    assertSameValue('185', $data['pageStart']);
    assertSameValue(
        ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => '10.1234/abcd', 'url' => 'https://doi.org/10.1234/abcd'],
        $data['identifier']
    );
    assertSameValue(['https://doi.org/10.1234/abcd'], $data['sameAs'], 'the DOI is the same work, published');
});

test('a YouTube video carries its embed URL and duration', function (): void {
    $video = new Resource('A talk', 22, null, [
        'fabio:hasURL' => [new Value(uriValue: 'https://www.youtube.com/watch?v=VNHDMq_Vz9g')],
        'dcterms:extent' => [new Value('PT48M32S')],
    ]);
    $builder = new StructuredData([22 => 'VideoObject']);

    $data = $builder->forResource($video, new SiteRepresentation(), null, null);

    assertSameValue('https://www.youtube.com/embed/VNHDMq_Vz9g', $data['embedUrl']);
    assertSameValue('PT48M32S', $data['duration']);
});

test('the home page WebSite no longer carries the retired SearchAction', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/Service/StructuredData.php');
    assertTrue(!str_contains($source, "'potentialAction'"), 'Google retired the sitelinks search box in 2024');
});

test('sameAs is limited to authority IRIs, in their canonical form', function (): void {
    $term = new Resource('Karakul sheep', 6, 'Authority file', [
        'dcterms:identifier' => [
            new Value(uriValue: 'https://www.wikidata.org/wiki/Q1189283'),
            new Value(uriValue: 'https://www.youtube.com/playlist?list=PL123'),
            new Value(uriValue: 'https://gepris.dfg.de/gepris/projekt/390713894'),
            new Value(uriValue: 'http://d-nb.info/gnd/4163233-2'),
            new Value(uriValue: 'https://orcid.org/0000-0003-0959-2092'),
            new Value(uriValue: 'https://id.loc.gov/authorities/subjects/sh85071763.html'),
            new Value(uriValue: 'https://www.geonames.org/2950159/berlin.html'),
            new Value(uriValue: 'https://ror.org/0234wmv40'),
            new Value(uriValue: 'https://viaf.org/viaf/12345/'),
            new Value(uriValue: 'https://isni.org/isni/000000012146438X'),
            new Value('Halle Fotoglasplatten'),
        ],
    ]);
    $builder = new StructuredData([6 => 'DefinedTerm']);

    $data = $builder->forResource($term, new SiteRepresentation(), null, null);

    assertSameValue([
        'http://www.wikidata.org/entity/Q1189283',
        'https://d-nb.info/gnd/4163233-2',
        'https://orcid.org/0000-0003-0959-2092',
        'http://id.loc.gov/authorities/subjects/sh85071763',
        'https://sws.geonames.org/2950159/',
        'https://ror.org/0234wmv40',
        'http://viaf.org/viaf/12345',
        'https://isni.org/isni/000000012146438X',
    ], $data['sameAs']);
});
