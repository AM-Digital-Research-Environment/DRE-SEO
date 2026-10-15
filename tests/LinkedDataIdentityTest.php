<?php
declare(strict_types=1);

use DRESeo\Service\Citation\Creator;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\Signposting;
use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;

require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/LinkedDataIdentity.php';
require_once __DIR__ . '/../src/Service/StructuredData.php';
require_once __DIR__ . '/../src/Service/Signposting.php';

const LD_BASE = 'https://data.example.test';

/**
 * A stand-in for DRE Linked Data's `dreLinkedData` helper: the same shape, and
 * nothing for the ids in $hidden, as the module returns for a private record.
 *
 * @param int[] $hidden
 */
function linkedDataIdentity(array $hidden = []): LinkedDataIdentity
{
    return new LinkedDataIdentity(static fn () => static function (Resource $resource) use ($hidden): array {
        $id = $resource->id();
        if (in_array($id, $hidden, true)) {
            return [];
        }
        return [
            'uri' => LD_BASE . '/resource/' . $id,
            'downloads' => [
                ['label' => 'Turtle', 'type' => 'text/turtle', 'url' => LD_BASE . '/resource/' . $id . '.ttl'],
                ['label' => 'N-Triples', 'type' => 'application/n-triples', 'url' => LD_BASE . '/resource/' . $id . '.nt'],
                ['label' => 'JSON-LD', 'type' => 'application/ld+json', 'url' => LD_BASE . '/resource/' . $id . '.jsonld'],
            ],
        ];
    });
}

function linkedThesis(): Item
{
    return new Item('A thesis', 30, 'Thesis', [
        'dcterms:creator' => [new Value(resource: new Resource('Example, Ada', 4, idValue: 101))],
        'dcterms:isPartOf' => [new Value(resource: new Resource('A series', 29, idValue: 102))],
        'dcterms:subject' => [
            new Value(resource: new Resource('Gender', 6, idValue: 103)),
            new Value(resource: new Resource('Private term', 6, idValue: 104)),
            new Value('Free keyword'),
        ],
        'dcterms:spatial' => [new Value(resource: new Resource('Bayreuth', 3, idValue: 105))],
    ], 'https://example.test/s/amira/item/41980', 41980);
}

test('without DRE Linked Data the identity is empty, and a failing helper is contained', function (): void {
    $thesis = linkedThesis();
    foreach ([
        new LinkedDataIdentity(),
        new LinkedDataIdentity(static fn () => null),
        new LinkedDataIdentity(static fn () => throw new RuntimeException('no helper manager')),
        new LinkedDataIdentity(static fn () => static fn () => throw new RuntimeException('broken')),
    ] as $identity) {
        assertSameValue(null, $identity->uri($thesis));
        assertSameValue([], $identity->documents($thesis));
    }
});

test('the schema.org record and its linked records take their resource URIs as @id', function (): void {
    $builder = new StructuredData([30 => 'Thesis'], 'CreativeWork', null, linkedDataIdentity(hidden: [104]));

    $data = $builder->forResource(linkedThesis(), new SiteRepresentation(), 'https://example.test/s/amira/item/41980', null);

    assertSameValue(LD_BASE . '/resource/41980', $data['@id']);
    assertSameValue('https://example.test/s/amira/item/41980', $data['url'], 'url stays the record page');
    assertSameValue(['@context', '@id', '@type', 'name'], array_slice(array_keys($data), 0, 4));
    assertSameValue(LD_BASE . '/resource/101', $data['author'][0]['@id']);
    assertSameValue('Ada Example', $data['author'][0]['name']);
    assertSameValue(LD_BASE . '/resource/102', $data['isPartOf']['@id']);
    assertSameValue('Gender, Private term, Free keyword', $data['keywords'], 'keywords keep every subject');
    assertSameValue(
        [['@type' => 'DefinedTerm', '@id' => LD_BASE . '/resource/103', 'name' => 'Gender']],
        $data['about'],
        'only subjects with a published resource URI are named as nodes'
    );
    assertSameValue(
        [['@type' => 'Place', '@id' => LD_BASE . '/resource/105', 'name' => 'Bayreuth']],
        $data['spatialCoverage']
    );
});

test('without DRE Linked Data the schema.org block is unchanged', function (): void {
    $builder = new StructuredData([30 => 'Thesis']);

    $data = $builder->forResource(linkedThesis(), new SiteRepresentation(), 'https://example.test/s/amira/item/41980', null);

    $json = (string) json_encode($data);
    assertTrue(!str_contains($json, '"@id"'), 'no @id anywhere: ' . $json);
    assertTrue(!isset($data['about']), 'no subject nodes without URIs');
    assertSameValue([['@type' => 'Place', 'name' => 'Bayreuth']], $data['spatialCoverage']);
    assertSameValue(['name' => 'A series', 'url' => 'https://example.test/s/amira/item/1'], array_diff_key($data['isPartOf'], ['@type' => 1]));
});

test('a linked agent keeps its parsed name parts beside its @id', function (): void {
    $builder = new StructuredData([30 => 'Thesis'], 'CreativeWork', null, linkedDataIdentity());

    $author = $builder->forResource(linkedThesis(), new SiteRepresentation(), null, null)['author'][0];

    assertSameValue(['@type', '@id', 'name', 'givenName', 'familyName', 'url'], array_keys($author));
    assertSameValue(Creator::parse('Example, Ada', false)->given, $author['givenName']);
});

test('Signposting describes the record by its RDF documents when DRE Linked Data is installed', function (): void {
    $identity = linkedDataIdentity();
    $signposting = new Signposting(new StructuredData([30 => 'Thesis'], 'CreativeWork', null, $identity), $identity);

    $links = $signposting->links(linkedThesis(), 'https://example.test/s/amira/item/41980', 'https://example.test', true);

    $describedBy = array_values(array_filter($links, static fn (array $link) => $link['rel'] === 'describedby'));
    assertSameValue([
        ['https://example.test/cite/41980/csljson', 'application/vnd.citationstyles.csl+json'],
        ['https://example.test/cite/41980/bibtex', 'application/x-bibtex'],
        ['https://example.test/cite/41980/ris', 'application/x-research-info-systems'],
        [LD_BASE . '/resource/41980.ttl', 'text/turtle'],
        [LD_BASE . '/resource/41980.nt', 'application/n-triples'],
        [LD_BASE . '/resource/41980.jsonld', 'application/ld+json'],
    ], array_map(static fn (array $link) => [$link['href'], $link['type']], $describedBy));
    assertTrue(!str_contains((string) json_encode($links), '/api/items/'), 'the Omeka API is no longer offered');
    assertSameValue(['rel' => 'cite-as', 'href' => LD_BASE . '/resource/41980'], $links[0], 'cite-as is the persistent URI');
    assertTrue(in_array(['rel' => 'type', 'href' => 'https://schema.org/Thesis'], $links, true), 'the schema.org type is linked');
});

test('Signposting falls back to the Omeka API description without DRE Linked Data', function (): void {
    $signposting = new Signposting(new StructuredData([30 => 'Thesis']), new LinkedDataIdentity());

    $links = $signposting->links(linkedThesis(), 'https://example.test/s/amira/item/41980', 'https://example.test', false);

    $describedBy = array_values(array_filter($links, static fn (array $link) => $link['rel'] === 'describedby'));
    assertSameValue(
        [['rel' => 'describedby', 'href' => 'https://example.test/api/items/41980', 'type' => 'application/ld+json']],
        $describedBy
    );
});

test('the Link header carries the same set, in RFC 8288 syntax, and is always a valid header value', function (): void {
    $header = Signposting::header([
        ['rel' => 'cite-as', 'href' => 'https://doi.org/10.1234/abc'],
        ['rel' => 'describedby', 'href' => LD_BASE . '/resource/7.ttl', 'type' => 'text/turtle'],
        ['rel' => 'item', 'href' => 'https://example.test/files/original/Übersicht <1>.pdf', 'type' => 'application/pdf'],
    ]);

    assertSameValue(
        '<https://doi.org/10.1234/abc>; rel="cite-as", '
            . '<' . LD_BASE . '/resource/7.ttl>; rel="describedby"; type="text/turtle", '
            . '<https://example.test/files/original/%C3%9Cbersicht%20%3C1%3E.pdf>; rel="item"; type="application/pdf"',
        $header
    );
    assertTrue(preg_match('/\A[\x20-\x7E]+\z/', $header) === 1, 'printable ASCII only');
});

test('cite-as is the page without DRE Linked Data, and never the DOI', function (): void {
    $thesis = new Item('A thesis', 30, 'Thesis', [
        'bibo:doi' => [new Value('10.1234/abcd')],
    ], 'https://example.test/s/amira/item/41980', 41980);

    $without = (new Signposting(new StructuredData([30 => 'Thesis']), new LinkedDataIdentity()))
        ->links($thesis, 'https://example.test/s/amira/item/41980', 'https://example.test', false);
    $identity = linkedDataIdentity();
    $with = (new Signposting(new StructuredData([30 => 'Thesis'], 'CreativeWork', null, $identity), $identity))
        ->links($thesis, 'https://example.test/s/amira/item/41980', 'https://example.test', false);

    assertSameValue('https://example.test/s/amira/item/41980', $without[0]['href']);
    assertSameValue(LD_BASE . '/resource/41980', $with[0]['href']);
    assertTrue(!str_contains((string) json_encode([$without, $with]), 'doi.org'), 'the DOI resolves to the publisher, not this record');
});

test('DRE Linked Data 0.3.0 sameAs is preferred, and each resource is described once', function (): void {
    $calls = 0;
    $helper = static function (Resource $resource) use (&$calls): array {
        $calls++;
        return [
            'uri' => LD_BASE . '/resource/' . $resource->id(),
            'page' => 'https://example.test/s/amira/item/' . $resource->id(),
            'sameAs' => ['http://www.wikidata.org/entity/Q1', 'https://d-nb.info/gnd/118540238', 'not an IRI'],
        ];
    };
    $identity = new LinkedDataIdentity(static fn () => $helper);
    $person = new Resource('Goethe, Johann Wolfgang von', 4, 'Person', [
        'dcterms:identifier' => [new Value(uriValue: 'https://www.youtube.com/playlist?list=abc')],
    ], idValue: 7);
    $builder = new StructuredData([4 => 'Person'], 'CreativeWork', null, $identity);

    $data = $builder->forResource($person, new SiteRepresentation(), null, null);
    $identity->uri($person);
    $identity->page($person);

    assertSameValue(['http://www.wikidata.org/entity/Q1', 'https://d-nb.info/gnd/118540238'], $data['sameAs']);
    assertSameValue('https://example.test/s/amira/item/7', $identity->page($person));
    assertSameValue(1, $calls, 'memoised per resource');
});

test('DRE Linked Data 0.2.0 has no sameAs or dataset: the caller falls back', function (): void {
    $identity = linkedDataIdentity();
    $person = new Resource('Example, Ada', 4, 'Person', [
        'dcterms:identifier' => [new Value(uriValue: 'https://www.wikidata.org/wiki/Q42')],
    ], idValue: 8);

    assertSameValue(null, $identity->sameAs($person));
    assertSameValue(null, $identity->page($person));
    assertSameValue([], $identity->dataset(), 'a closure helper has no dataset()');
    $data = (new StructuredData([4 => 'Person'], 'CreativeWork', null, $identity))
        ->forResource($person, new SiteRepresentation(), null, null);
    assertSameValue(['http://www.wikidata.org/entity/Q42'], $data['sameAs']);
});

test('dataset() is read from a helper object that has it, and a failure is contained', function (): void {
    $helper = new class {
        public function __invoke(Resource $resource): array
        {
            return [];
        }

        public function dataset(): array
        {
            return ['uri' => LD_BASE . '/linked-data/void#dataset'];
        }
    };
    $broken = new class {
        public function __invoke(Resource $resource): array
        {
            return [];
        }

        public function dataset(): array
        {
            throw new RuntimeException('no dump');
        }
    };

    assertSameValue(LD_BASE . '/linked-data/void#dataset', (new LinkedDataIdentity(static fn () => $helper))->dataset()['uri']);
    assertSameValue([], (new LinkedDataIdentity(static fn () => $broken))->dataset());
});

test('Signposting links a work to its authors by ORCID iD, else by resource URI', function (): void {
    $identity = linkedDataIdentity();
    $signposting = new Signposting(new StructuredData([15 => 'Book', 4 => 'Person'], 'CreativeWork', null, $identity), $identity);
    $book = new Item('A book', 15, 'Book', [
        'bibo:authorList' => [
            new Value(resource: new Resource('Madore, Frédérick', 4, 'Person', [
                'dcterms:identifier' => [new Value(uriValue: 'https://orcid.org/0000-0003-0959-2092')],
            ], idValue: 201)),
            new Value(resource: new Resource('Example, Ada', 4, 'Person', [], idValue: 202)),
            new Value('Name, Only'),
        ],
    ], idValue: 200);

    $links = $signposting->links($book, 'https://example.test/s/amira/item/200', 'https://example.test', false);

    assertSameValue(
        ['https://orcid.org/0000-0003-0959-2092', LD_BASE . '/resource/202'],
        array_column(array_values(array_filter($links, static fn (array $l) => $l['rel'] === 'author')), 'href')
    );
});

test('Signposting links a licence only when it resolves to a URL', function (): void {
    $signposting = new Signposting(new StructuredData([20 => 'Dataset']), new LinkedDataIdentity());
    $licensed = static fn (Resource $licence): Item => new Item('Data', 20, null, [
        'dcterms:license' => [new Value(resource: $licence)],
    ], idValue: 300);

    $cc = $signposting->links($licensed(new Resource('CC-BY-NC-SA-4.0', 6)), 'https://example.test/s/amira/item/300', 'https://example.test', false);
    $custom = $signposting->links($licensed(new Resource('Custom terms', 6)), 'https://example.test/s/amira/item/300', 'https://example.test', false);

    assertTrue(in_array(['rel' => 'license', 'href' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/'], $cc, true));
    assertSameValue([], array_values(array_filter($custom, static fn (array $l) => $l['rel'] === 'license')));
});

test('item-set and media pages are signposted too, and a file points at its record', function (): void {
    $identity = linkedDataIdentity();
    $signposting = new Signposting(new StructuredData([]), $identity);
    $itemSet = new Omeka\Api\Representation\ItemSetRepresentation('Publications', idValue: 29918);
    $media = new Omeka\Api\Representation\MediaRepresentation('scan.pdf', idValue: 500);
    $media->originalUrlValue = 'https://example.test/files/original/scan.pdf';
    $media->mediaTypeValue = 'application/pdf';

    $setLinks = $signposting->links($itemSet, 'https://example.test/s/amira/item-set/29918', 'https://example.test', true);
    $mediaLinks = $signposting->links($media, 'https://example.test/s/amira/media/500', 'https://example.test', true, 'https://example.test/s/amira/item/41980');

    assertSameValue(['rel' => 'cite-as', 'href' => LD_BASE . '/resource/29918'], $setLinks[0]);
    assertTrue(in_array(['rel' => 'describedby', 'href' => LD_BASE . '/resource/29918.ttl', 'type' => 'text/turtle'], $setLinks, true));
    assertTrue(in_array(['rel' => 'type', 'href' => 'https://schema.org/Collection'], $setLinks, true));
    assertTrue(!str_contains((string) json_encode($setLinks), '/cite/'), 'no citation downloads for a set');
    assertTrue(in_array(['rel' => 'collection', 'href' => 'https://example.test/s/amira/item/41980'], $mediaLinks, true));
    assertTrue(in_array(['rel' => 'item', 'href' => 'https://example.test/files/original/scan.pdf', 'type' => 'application/pdf'], $mediaLinks, true));
});
