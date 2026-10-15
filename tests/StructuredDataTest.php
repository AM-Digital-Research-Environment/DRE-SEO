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
    $language = new Resource('English', 6, null, ['dcterms:alternative' => [new Value('eng')]]);
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
    assertSameValue('en', $data['inLanguage'], 'a BCP 47 tag, not the label');
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

test('the home page WebSite names its home page and the site search', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $site = new SiteRepresentation('amira', 'AMIRA', new Omeka\Api\Representation\SitePageRepresentation('home'));
    $builder = new StructuredData([], 'CreativeWork', null, null, ['search_path' => $config['dre_seo']['structured_data']['search_path']]);

    $data = $builder->webSite(new Laminas\View\Renderer\PhpRenderer(), $site);

    assertSameValue('https://example.test/s/amira/page/home', $data['url']);
    assertSameValue('SearchAction', $data['potentialAction']['@type']);
    assertSameValue(
        'https://example.test/s/amira/index/search?fulltext_search={search_term_string}',
        $data['potentialAction']['target']['urlTemplate']
    );
    assertSameValue('required name=search_term_string', $data['potentialAction']['query-input']);
    $without = (new StructuredData([]))->webSite(new Laminas\View\Renderer\PhpRenderer(), $site);
    assertTrue(!isset($without['potentialAction']), 'no search path, no action');
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

test('a linked record is typed as its own page types it, whatever its role', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);
    $project = new Resource('Karakul Circulations', 5, 'Project', [], null, 1794, 'foaf:Project');
    $item = new Omeka\Api\Representation\ItemRepresentation('Alles was irgendwie nützt', 10, null, [
        'dcterms:isPartOf' => [new Value(resource: $project)],
        'frapo:isFundedBy' => [new Value(resource: new Resource('EXC 2052', 2))],
        'dcterms:subject' => [new Value(resource: new Resource('Germany', 3))],
    ]);

    $data = $builder->forResource($item, new SiteRepresentation(), null, null);

    assertSameValue('ResearchProject', $data['isPartOf']['@type'], 'the project page says ResearchProject');
    assertSameValue('Organization', $data['funder'][0]['@type']);
    $chapter = new Resource('A chapter', 14, null, [
        'dcterms:isPartOf' => [new Value(resource: new Resource('The book', 15))],
    ]);
    assertSameValue('Book', $builder->forResource($chapter, new SiteRepresentation(), null, null)['isPartOf']['@type']);
    $literal = new Resource('A chapter', 14, null, ['dcterms:isPartOf' => [new Value('A book known by name')]]);
    assertSameValue('Book', $builder->forResource($literal, new SiteRepresentation(), null, null)['isPartOf']['@type'], 'a name alone takes its role type');
});

test('dates are ISO 8601, and a video falls back to its creation date for uploadDate', function (): void {
    $builder = new StructuredData([22 => 'VideoObject', 15 => 'Book']);
    $book = new Resource('A book', 15, null, [
        'dcterms:issued' => [new Value('7 December 2018', rawValue: '2018-12-07')],
    ]);
    $undated = new Resource('A book', 15, null, ['dcterms:date' => [new Value('undated')]]);
    $video = new Resource('A video', 22, null, ['dcterms:created' => [new Value('1905-07-08', rawValue: '1905-07-08')]]);

    assertSameValue('2018-12-07', $builder->forResource($book, new SiteRepresentation(), null, null)['datePublished']);
    assertTrue(!isset($builder->forResource($undated, new SiteRepresentation(), null, null)['datePublished']), 'no year, no date');
    $videoData = $builder->forResource($video, new SiteRepresentation(), null, null);
    assertSameValue('1905-07-08', $videoData['uploadDate']);
    assertSameValue('1905-07-08', $videoData['dateCreated']);
});

test('languages are BCP 47 tags from the language record code', function (): void {
    $builder = new StructuredData([15 => 'Book']);
    $book = new Resource('A book', 15, null, ['dcterms:language' => [
        new Value(resource: new Resource('German', 6, null, ['dcterms:alternative' => [new Value('deu')]])),
        new Value(resource: new Resource('Nigerian Pidgin', 6, null, ['dcterms:alternative' => [new Value('pcm')]])),
        new Value(resource: new Resource('Dholuo', 6, null, ['dcterms:alternative' => [new Value('dholuo')]])),
    ]]);

    assertSameValue(['de', 'pcm'], $builder->forResource($book, new SiteRepresentation(), null, null)['inLanguage']);
});

test('a linked licence resolves to its URL, else is named under its resource URI', function (): void {
    $identity = new DRESeo\Service\LinkedDataIdentity(static fn () => static fn (Resource $r): array => ['uri' => 'https://example.test/resource/' . $r->id()]);
    $builder = new StructuredData([20 => 'Dataset'], 'CreativeWork', null, $identity);
    $licensed = static fn (Resource $licence): Resource => new Resource('Data', 20, null, [
        'dcterms:license' => [new Value(resource: $licence)],
    ]);

    // AMIRA's licence item 10297: a label and a Wikidata page, no licence URL.
    $byLabel = new Resource('CC-BY-NC-SA-4.0', 6, null, [
        'dcterms:identifier' => [new Value(uriValue: 'https://www.wikidata.org/wiki/Q42553662')],
    ], idValue: 10297);
    $byUrl = new Resource('Our licence', 6, null, [
        'dcterms:source' => [new Value(uriValue: 'https://rightsstatements.org/vocab/InC/1.0/')],
    ], idValue: 10298);
    $unknown = new Resource('Custom terms', 6, null, [], idValue: 10299);
    $literal = new Resource('Data', 20, null, ['dcterms:license' => [new Value('CC BY 3.0')]]);

    $site = new SiteRepresentation();
    assertSameValue('https://creativecommons.org/licenses/by-nc-sa/4.0/', $builder->forResource($licensed($byLabel), $site, null, null)['license']);
    assertSameValue('https://rightsstatements.org/vocab/InC/1.0/', $builder->forResource($licensed($byUrl), $site, null, null)['license']);
    assertSameValue(
        ['@type' => 'CreativeWork', '@id' => 'https://example.test/resource/10299', 'name' => 'Custom terms'],
        $builder->forResource($licensed($unknown), $site, null, null)['license']
    );
    assertSameValue('https://creativecommons.org/licenses/by/3.0/', $builder->forResource($literal, $site, null, null)['license']);
    assertSameValue('https://creativecommons.org/publicdomain/zero/1.0/', DRESeo\Service\Licence::urlForLabel('CC0 1.0 Universal'));
    assertSameValue(
        'https://creativecommons.org/licenses/by-nc-nd/4.0/',
        DRESeo\Service\Licence::urlForLabel('Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International')
    );
    assertSameValue(null, DRESeo\Service\Licence::urlForLabel('CC-NC-4.0'));
});

test('a research item states its conditions of access', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $builder = new StructuredData([10 => 'CreativeWork', 5 => 'ResearchProject'], 'CreativeWork', null, null, [
        'access_free' => $config['dre_seo']['structured_data']['access_free'],
        'access_statement' => static fn (): string => 'Ask the project.',
    ]);
    $project = new Resource('A project', 5, 'Project', [], null, 1794, 'foaf:Project');
    $researchItem = static fn (string ...$rights): Omeka\Api\Representation\ItemRepresentation => new Omeka\Api\Representation\ItemRepresentation('Recording', 10, null, [
        'dcterms:isPartOf' => [new Value(resource: $project)],
        'dcterms:accessRights' => array_map(static fn (string $right): Value => new Value(resource: new Resource($right, 6)), $rights),
    ]);
    $site = new SiteRepresentation();

    $noRawData = $builder->forResource($researchItem('No Raw Data'), $site, null, null);
    assertSameValue(['No Raw Data', 'Ask the project.'], $noRawData['conditionsOfAccess']);
    assertTrue(!isset($noRawData['isAccessibleForFree']), 'undecidable: left out');
    assertSameValue(true, $builder->forResource($researchItem('Public'), $site, null, null)['isAccessibleForFree']);
    assertSameValue(false, $builder->forResource($researchItem('Group'), $site, null, null)['isAccessibleForFree']);
    assertTrue(!isset($builder->forResource($researchItem('Public', 'Group'), $site, null, null)['isAccessibleForFree']), 'conflicting');

    $publication = new Omeka\Api\Representation\ItemRepresentation('A book', 15, null, [
        'dcterms:isPartOf' => [new Value(resource: new Resource('A series', 29))],
    ]);
    assertTrue(!isset($builder->forResource($publication, $site, null, null)['conditionsOfAccess']), 'not a research item');
});

test('the catalogue is a Dataset with a description Google accepts', function (): void {
    $site = new SiteRepresentation('amira', 'AMIRA', new Omeka\Api\Representation\SitePageRepresentation('home'));
    $builder = new StructuredData([]);
    $dataset = [
        'uri' => 'https://example.test/linked-data/void#dataset',
        'void' => 'https://example.test/linked-data/void',
        'title' => 'Africa Multiple Interactive Research Atlas (AMIRA)',
        'description' => 'Too short.',
        'licence' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'publisher' => ['name' => 'Africa Multiple Cluster of Excellence', 'uri' => 'http://www.wikidata.org/entity/Q141717295'],
        'modified' => '2026-10-14T14:17:01Z',
        'distributions' => [[
            'label' => 'N-Triples dump (gzip)', 'url' => 'https://example.test/linked-data/dump.nt.gz',
            'type' => 'application/n-triples', 'encoding' => 'application/gzip', 'bytes' => 1911482,
        ]],
    ];

    $data = $builder->dataset(new Laminas\View\Renderer\PhpRenderer(), $site, $dataset);

    assertSameValue('Dataset', $data['@type']);
    assertSameValue('https://example.test/linked-data/void#dataset', $data['@id']);
    assertTrue(mb_strlen($data['description']) >= StructuredData::DATASET_DESCRIPTION_MIN, 'at least 50 characters');
    assertSameValue('https://example.test/s/amira/page/home', $data['url']);
    assertSameValue('http://www.wikidata.org/entity/Q141717295', $data['publisher']['sameAs']);
    assertSameValue($data['publisher'], $data['creator']);
    assertSameValue(
        ['@type' => 'DataDownload', 'name' => 'N-Triples dump (gzip)', 'contentUrl' => 'https://example.test/linked-data/dump.nt.gz', 'encodingFormat' => 'application/n-triples', 'contentSize' => '1.9 MB'],
        $data['distribution'][0]
    );
    assertSameValue(['@type' => 'DataCatalog', 'name' => 'AMIRA', 'url' => 'https://example.test/s/amira/page/home'], $data['includedInDataCatalog']);
    assertSameValue(null, $builder->dataset(new Laminas\View\Renderer\PhpRenderer(), $site, []), 'nothing to describe');
});

test('an item set page is a CollectionPage about the item set', function (): void {
    $identity = new DRESeo\Service\LinkedDataIdentity(static fn () => static fn (Resource $r): array => ['uri' => 'https://example.test/resource/' . $r->id()]);
    $builder = new StructuredData([], 'CreativeWork', null, $identity);
    $itemSet = new Resource('Publications', null, null, [], null, 29918);

    $data = $builder->collectionPage(new Laminas\View\Renderer\PhpRenderer(), $itemSet, new SiteRepresentation(), 'https://example.test/s/amira/item-set/29918', 'The cluster bibliography.');

    assertSameValue('https://example.test/s/amira/item-set/29918', $data['@id']);
    assertSameValue(['@type' => 'Collection', '@id' => 'https://example.test/resource/29918', 'name' => 'Publications'], $data['mainEntity']);
});
