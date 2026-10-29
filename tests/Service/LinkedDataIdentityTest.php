<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\Citation\Creator;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\Signposting;
use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The identity DRE Linked Data lends a record — resource URIs, sameAs, the
 * catalogue Dataset — and the FAIR Signposting links built from it, with the
 * module installed, absent or failing.
 */
final class LinkedDataIdentityTest extends TestCase
{
    public const LD_BASE = 'https://data.example.test';

    /**
     * A stand-in for DRE Linked Data's `dreLinkedData` helper: the same shape, and
     * nothing for the ids in $hidden, as the module returns for a private record.
     *
     * @param int[] $hidden
     */
    private static function linkedDataIdentity(array $hidden = []): LinkedDataIdentity
    {
        return new LinkedDataIdentity(static fn () => static function (Resource $resource) use ($hidden): array {
            $id = $resource->id();
            if (in_array($id, $hidden, true)) {
                return [];
            }
            return [
                'uri' => self::LD_BASE . '/resource/' . $id,
                'downloads' => [
                    ['label' => 'Turtle', 'type' => 'text/turtle', 'url' => self::LD_BASE . '/resource/' . $id . '.ttl'],
                    ['label' => 'N-Triples', 'type' => 'application/n-triples', 'url' => self::LD_BASE . '/resource/' . $id . '.nt'],
                    ['label' => 'JSON-LD', 'type' => 'application/ld+json', 'url' => self::LD_BASE . '/resource/' . $id . '.jsonld'],
                ],
            ];
        });
    }

    private static function linkedThesis(): Item
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

    public function testWithoutDRELinkedDataTheIdentityIsEmptyAndAFailingHelperIsContained(): void
    {
        $thesis = self::linkedThesis();
        foreach (
            [
            new LinkedDataIdentity(),
            new LinkedDataIdentity(static fn () => null),
            new LinkedDataIdentity(static fn () => throw new RuntimeException('no helper manager')),
            new LinkedDataIdentity(static fn () => static fn () => throw new RuntimeException('broken')),
            ] as $identity
        ) {
            $this->assertSame(null, $identity->uri($thesis));
            $this->assertSame([], $identity->documents($thesis));
        }
    }

    public function testTheSchemaOrgRecordAndItsLinkedRecordsTakeTheirResourceURIsAsId(): void
    {
        $builder = new StructuredData([30 => 'Thesis'], 'CreativeWork', null, self::linkedDataIdentity(hidden: [104]));

        $data = $builder->forResource(self::linkedThesis(), new SiteRepresentation(), 'https://example.test/s/amira/item/41980', null);

        $this->assertSame(self::LD_BASE . '/resource/41980', $data['@id']);
        $this->assertSame('https://example.test/s/amira/item/41980', $data['url'], 'url stays the record page');
        $this->assertSame(['@context', '@id', '@type', 'name'], array_slice(array_keys($data), 0, 4));
        $this->assertSame(self::LD_BASE . '/resource/101', $data['author'][0]['@id']);
        $this->assertSame('Ada Example', $data['author'][0]['name']);
        $this->assertSame(self::LD_BASE . '/resource/102', $data['isPartOf']['@id']);
        $this->assertSame('Gender, Private term, Free keyword', $data['keywords'], 'keywords keep every subject');
        $this->assertSame(
            [['@type' => 'DefinedTerm', '@id' => self::LD_BASE . '/resource/103', 'name' => 'Gender']],
            $data['about'],
            'only subjects with a published resource URI are named as nodes'
        );
        $this->assertSame(
            [['@type' => 'Place', '@id' => self::LD_BASE . '/resource/105', 'name' => 'Bayreuth']],
            $data['spatialCoverage']
        );
    }

    public function testWithoutDRELinkedDataTheSchemaOrgBlockIsUnchanged(): void
    {
        $builder = new StructuredData([30 => 'Thesis']);

        $data = $builder->forResource(self::linkedThesis(), new SiteRepresentation(), 'https://example.test/s/amira/item/41980', null);

        $json = (string) json_encode($data);
        $this->assertTrue(!str_contains($json, '"@id"'), 'no @id anywhere: ' . $json);
        $this->assertTrue(!isset($data['about']), 'no subject nodes without URIs');
        $this->assertSame([['@type' => 'Place', 'name' => 'Bayreuth']], $data['spatialCoverage']);
        $this->assertSame(['name' => 'A series', 'url' => 'https://example.test/s/amira/item/1'], array_diff_key($data['isPartOf'], ['@type' => 1]));
    }

    public function testALinkedAgentKeepsItsParsedNamePartsBesideItsId(): void
    {
        $builder = new StructuredData([30 => 'Thesis'], 'CreativeWork', null, self::linkedDataIdentity());

        $author = $builder->forResource(self::linkedThesis(), new SiteRepresentation(), null, null)['author'][0];

        $this->assertSame(['@type', '@id', 'name', 'givenName', 'familyName', 'url'], array_keys($author));
        $this->assertSame(Creator::parse('Example, Ada', false)->given, $author['givenName']);
    }

    public function testSignpostingDescribesTheRecordByItsRDFDocumentsWhenDRELinkedDataIsInstalled(): void
    {
        $identity = self::linkedDataIdentity();
        $signposting = new Signposting(new StructuredData([30 => 'Thesis'], 'CreativeWork', null, $identity), $identity);

        $links = $signposting->links(self::linkedThesis(), 'https://example.test/s/amira/item/41980', 'https://example.test', true);

        $describedBy = array_values(array_filter($links, static fn (array $link) => $link['rel'] === 'describedby'));
        $this->assertSame([
            ['https://example.test/cite/41980/csljson', 'application/vnd.citationstyles.csl+json'],
            ['https://example.test/cite/41980/bibtex', 'application/x-bibtex'],
            ['https://example.test/cite/41980/ris', 'application/x-research-info-systems'],
            [self::LD_BASE . '/resource/41980.ttl', 'text/turtle'],
            [self::LD_BASE . '/resource/41980.nt', 'application/n-triples'],
            [self::LD_BASE . '/resource/41980.jsonld', 'application/ld+json'],
        ], array_map(static fn (array $link) => [$link['href'], $link['type']], $describedBy));
        $this->assertTrue(!str_contains((string) json_encode($links), '/api/items/'), 'the Omeka API is no longer offered');
        $this->assertSame(['rel' => 'cite-as', 'href' => self::LD_BASE . '/resource/41980'], $links[0], 'cite-as is the persistent URI');
        $this->assertTrue(in_array(['rel' => 'type', 'href' => 'https://schema.org/Thesis'], $links, true), 'the schema.org type is linked');
    }

    public function testSignpostingFallsBackToTheOmekaAPIDescriptionWithoutDRELinkedData(): void
    {
        $signposting = new Signposting(new StructuredData([30 => 'Thesis']), new LinkedDataIdentity());

        $links = $signposting->links(self::linkedThesis(), 'https://example.test/s/amira/item/41980', 'https://example.test', false);

        $describedBy = array_values(array_filter($links, static fn (array $link) => $link['rel'] === 'describedby'));
        $this->assertSame(
            [['rel' => 'describedby', 'href' => 'https://example.test/api/items/41980', 'type' => 'application/ld+json']],
            $describedBy
        );
    }

    public function testTheLinkHeaderCarriesTheSameSetInRFC8288SyntaxAndIsAlwaysAValidHeaderValue(): void
    {
        $header = Signposting::header([
            ['rel' => 'cite-as', 'href' => 'https://doi.org/10.1234/abc'],
            ['rel' => 'describedby', 'href' => self::LD_BASE . '/resource/7.ttl', 'type' => 'text/turtle'],
            ['rel' => 'item', 'href' => 'https://example.test/files/original/Übersicht <1>.pdf', 'type' => 'application/pdf'],
        ]);

        $this->assertSame(
            '<https://doi.org/10.1234/abc>; rel="cite-as", '
                . '<' . self::LD_BASE . '/resource/7.ttl>; rel="describedby"; type="text/turtle", '
                . '<https://example.test/files/original/%C3%9Cbersicht%20%3C1%3E.pdf>; rel="item"; type="application/pdf"',
            $header
        );
        $this->assertTrue(preg_match('/\A[\x20-\x7E]+\z/', $header) === 1, 'printable ASCII only');
    }

    public function testCiteAsIsThePageWithoutDRELinkedDataAndNeverTheDOI(): void
    {
        $thesis = new Item('A thesis', 30, 'Thesis', [
            'bibo:doi' => [new Value('10.1234/abcd')],
        ], 'https://example.test/s/amira/item/41980', 41980);

        $without = (new Signposting(new StructuredData([30 => 'Thesis']), new LinkedDataIdentity()))
            ->links($thesis, 'https://example.test/s/amira/item/41980', 'https://example.test', false);
        $identity = self::linkedDataIdentity();
        $with = (new Signposting(new StructuredData([30 => 'Thesis'], 'CreativeWork', null, $identity), $identity))
            ->links($thesis, 'https://example.test/s/amira/item/41980', 'https://example.test', false);

        $this->assertSame('https://example.test/s/amira/item/41980', $without[0]['href']);
        $this->assertSame(self::LD_BASE . '/resource/41980', $with[0]['href']);
        $this->assertTrue(!str_contains((string) json_encode([$without, $with]), 'doi.org'), 'the DOI resolves to the publisher, not this record');
    }

    public function testDRELinkedData030SameAsIsPreferredAndEachResourceIsDescribedOnce(): void
    {
        $calls = 0;
        $helper = static function (Resource $resource) use (&$calls): array {
            $calls++;
            return [
                'uri' => self::LD_BASE . '/resource/' . $resource->id(),
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

        $this->assertSame(['http://www.wikidata.org/entity/Q1', 'https://d-nb.info/gnd/118540238'], $data['sameAs']);
        $this->assertSame('https://example.test/s/amira/item/7', $identity->page($person));
        $this->assertSame(1, $calls, 'memoised per resource');
    }

    public function testDRELinkedData020HasNoSameAsOrDatasetTheCallerFallsBack(): void
    {
        $identity = self::linkedDataIdentity();
        $person = new Resource('Example, Ada', 4, 'Person', [
            'dcterms:identifier' => [new Value(uriValue: 'https://www.wikidata.org/wiki/Q42')],
        ], idValue: 8);

        $this->assertSame(null, $identity->sameAs($person));
        $this->assertSame(null, $identity->page($person));
        $this->assertSame([], $identity->dataset(), 'a closure helper has no dataset()');
        $data = (new StructuredData([4 => 'Person'], 'CreativeWork', null, $identity))
            ->forResource($person, new SiteRepresentation(), null, null);
        $this->assertSame(['http://www.wikidata.org/entity/Q42'], $data['sameAs']);
    }

    public function testDatasetIsReadFromAHelperObjectThatHasItAndAFailureIsContained(): void
    {
        $helper = new class {
            /** @return array<string,mixed> */
            public function __invoke(Resource $resource): array
            {
                return [];
            }

            /** @return array<string,mixed> */
            public function dataset(): array
            {
                return ['uri' => LinkedDataIdentityTest::LD_BASE . '/linked-data/void#dataset'];
            }
        };
        $broken = new class {
            /** @return array<string,mixed> */
            public function __invoke(Resource $resource): array
            {
                return [];
            }

            /** @return array<string,mixed> */
            public function dataset(): array
            {
                throw new RuntimeException('no dump');
            }
        };

        $this->assertSame(self::LD_BASE . '/linked-data/void#dataset', (new LinkedDataIdentity(static fn () => $helper))->dataset()['uri']);
        $this->assertSame([], (new LinkedDataIdentity(static fn () => $broken))->dataset());
    }

    public function testSignpostingLinksAWorkToItsAuthorsByORCIDIDElseByResourceURI(): void
    {
        $identity = self::linkedDataIdentity();
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

        $this->assertSame(
            ['https://orcid.org/0000-0003-0959-2092', self::LD_BASE . '/resource/202'],
            array_column(array_values(array_filter($links, static fn (array $l) => $l['rel'] === 'author')), 'href')
        );
    }

    public function testSignpostingLinksALicenceOnlyWhenItResolvesToAURL(): void
    {
        $signposting = new Signposting(new StructuredData([20 => 'Dataset']), new LinkedDataIdentity());
        $licensed = static fn (Resource $licence): Item => new Item('Data', 20, null, [
            'dcterms:license' => [new Value(resource: $licence)],
        ], idValue: 300);

        $cc = $signposting->links($licensed(new Resource('CC-BY-NC-SA-4.0', 6)), 'https://example.test/s/amira/item/300', 'https://example.test', false);
        $custom = $signposting->links($licensed(new Resource('Custom terms', 6)), 'https://example.test/s/amira/item/300', 'https://example.test', false);

        $this->assertTrue(in_array(['rel' => 'license', 'href' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/'], $cc, true));
        $this->assertSame([], array_values(array_filter($custom, static fn (array $l) => $l['rel'] === 'license')));
    }

    public function testItemSetAndMediaPagesAreSignpostedTooAndAFilePointsAtItsRecord(): void
    {
        $identity = self::linkedDataIdentity();
        $signposting = new Signposting(new StructuredData([]), $identity);
        $itemSet = new ItemSetRepresentation('Publications', idValue: 29918);
        $media = new MediaRepresentation('scan.pdf', idValue: 500);
        $media->originalUrlValue = 'https://example.test/files/original/scan.pdf';
        $media->mediaTypeValue = 'application/pdf';

        $setLinks = $signposting->links($itemSet, 'https://example.test/s/amira/item-set/29918', 'https://example.test', true);
        $mediaLinks = $signposting->links($media, 'https://example.test/s/amira/media/500', 'https://example.test', true, 'https://example.test/s/amira/item/41980');

        $this->assertSame(['rel' => 'cite-as', 'href' => self::LD_BASE . '/resource/29918'], $setLinks[0]);
        $this->assertTrue(in_array(['rel' => 'describedby', 'href' => self::LD_BASE . '/resource/29918.ttl', 'type' => 'text/turtle'], $setLinks, true));
        $this->assertTrue(in_array(['rel' => 'type', 'href' => 'https://schema.org/Collection'], $setLinks, true));
        $this->assertTrue(!str_contains((string) json_encode($setLinks), '/cite/'), 'no citation downloads for a set');
        $this->assertTrue(in_array(['rel' => 'collection', 'href' => 'https://example.test/s/amira/item/41980'], $mediaLinks, true));
        $this->assertTrue(in_array(['rel' => 'item', 'href' => 'https://example.test/files/original/scan.pdf', 'type' => 'application/pdf'], $mediaLinks, true));
    }
}
