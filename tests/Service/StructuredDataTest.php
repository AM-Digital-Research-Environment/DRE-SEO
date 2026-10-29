<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\Licence;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\StructuredData;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;

/** The schema.org JSON-LD for records, pages and the site. */
final class StructuredDataTest extends TestCase
{
    public function testYouTubeResourcesEmitCurrentVideoObjectFields(): void
    {
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

        $this->assertSame('VideoObject', $data['@type']);
        $this->assertSame('2026-07-20', $data['uploadDate']);
        $this->assertSame('https://i.ytimg.com/vi/abc123/hqdefault.jpg', $data['thumbnailUrl']);
        $this->assertSame(['https://www.youtube.com/watch?v=abc123'], $data['sameAs']);
        $this->assertSame('Ada Example', $data['author'][0]['name']);
        $this->assertSame('en', $data['inLanguage'], 'a BCP 47 tag, not the label');
        $this->assertSame('Research stories', $data['isPartOf']['name']);
    }

    public function testPersonSameAsComesFromTheRDSpaceHandleNeverAWissKIURL(): void
    {
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

        $this->assertSame('Person', $data['@type']);
        $this->assertSame(['https://hdl.handle.net/10962/12345'], $data['sameAs']);
    }

    public function testAPersonIsNamedInNaturalOrderAndLinkedToItsAuthorityFiles(): void
    {
        $person = new Resource('Drescher, Martina', 4, 'Person', [
            'dcterms:identifier' => [new Value('GND 124120881', uriValue: 'https://d-nb.info/gnd/124120881')],
        ]);
        $builder = new StructuredData([4 => 'Person']);

        $data = $builder->forResource($person, new SiteRepresentation(), 'https://example.test/s/amira/item/46', null);

        $this->assertSame('Martina Drescher', $data['name']);
        $this->assertSame('Martina', $data['givenName']);
        $this->assertSame('Drescher', $data['familyName']);
        $this->assertSame('Drescher, Martina', $data['alternateName']);
        $this->assertSame(['https://d-nb.info/gnd/124120881'], $data['sameAs']);
        $this->assertTrue(!isset($data['image']), 'no image is claimed for a record that has none');
    }

    public function testASubjectHeadingIsADefinedTermInItsVocabulary(): void
    {
        $term = new Resource('Gender', 6, 'Authority file', [
            'dcterms:type' => [new Value(resource: new Resource('Library of Congress Subject Headings', 6))],
            'dcterms:identifier' => [
                new Value('sh00002547', uriValue: 'http://id.loc.gov/authorities/subjects/sh00002547'),
                new Value('Q162378', uriValue: 'http://www.wikidata.org/entity/Q162378'),
            ],
            // Creative-work properties must not leak onto a term.
            'dcterms:subject' => [new Value('Something')],
        ]);
        $config = require __DIR__ . '/../../config/module.config.php';
        $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);

        $data = $builder->forResource($term, new SiteRepresentation(), null, null);

        $this->assertSame('DefinedTerm', $data['@type']);
        $this->assertSame('Library of Congress Subject Headings', $data['inDefinedTermSet']['name']);
        $this->assertSame(2, count($data['sameAs']));
        $this->assertTrue(!isset($data['keywords']), 'a term has no keywords of its own');
    }

    public function testAJournalIsAPeriodicalAndABareWikidataIdBecomesItsEntityURI(): void
    {
        $journal = new Resource('Zeitschrift für französische Sprache und Literatur', 23, 'Journal', [
            'bibo:issn' => [new Value('2366-2425')],
            'dcterms:identifier' => [new Value('Q15755461', uriValue: 'Q15755461')],
        ]);
        $config = require __DIR__ . '/../../config/module.config.php';
        $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);

        $data = $builder->forResource($journal, new SiteRepresentation(), null, null);

        $this->assertSame('Periodical', $data['@type']);
        $this->assertSame('2366-2425', $data['issn']);
        $this->assertSame(['http://www.wikidata.org/entity/Q15755461'], $data['sameAs']);
    }

    public function testAPlaceHasNumericCoordinatesAndThePlaceThatContainsIt(): void
    {
        $place = new Resource('Sankuru', 3, 'Location', [
            'geo:lat' => [new Value('-3.3575')],
            'geo:long' => [new Value('23.59666667')],
            'dcterms:isPartOf' => [new Value(resource: new Resource('Democratic Republic of the Congo', 3))],
        ]);
        $builder = new StructuredData([3 => 'Place']);

        $data = $builder->forResource($place, new SiteRepresentation(), null, null);

        $this->assertSame(-3.3575, $data['geo']['latitude']);
        $this->assertSame('Democratic Republic of the Congo', $data['containedInPlace']['name']);
    }

    public function testAnArticleSitsInItsPeriodicalVolumeAndIssueWithItsDOI(): void
    {
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

        $this->assertSame('Jean-Louis Triaud', $data['author'][0]['name']);
        $this->assertSame('PublicationIssue', $data['isPartOf']['@type']);
        $this->assertSame('3', $data['isPartOf']['issueNumber']);
        $this->assertSame('42', $data['isPartOf']['isPartOf']['volumeNumber']);
        $this->assertSame('0021-8537', $data['isPartOf']['isPartOf']['isPartOf']['issn']);
        $this->assertSame('185', $data['pageStart']);
        $this->assertSame(
            ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => '10.1234/abcd', 'url' => 'https://doi.org/10.1234/abcd'],
            $data['identifier']
        );
        $this->assertSame(['https://doi.org/10.1234/abcd'], $data['sameAs'], 'the DOI is the same work, published');
    }

    public function testAYouTubeVideoCarriesItsEmbedURLAndDuration(): void
    {
        $video = new Resource('A talk', 22, null, [
            'fabio:hasURL' => [new Value(uriValue: 'https://www.youtube.com/watch?v=VNHDMq_Vz9g')],
            'dcterms:extent' => [new Value('PT48M32S')],
        ]);
        $builder = new StructuredData([22 => 'VideoObject']);

        $data = $builder->forResource($video, new SiteRepresentation(), null, null);

        $this->assertSame('https://www.youtube.com/embed/VNHDMq_Vz9g', $data['embedUrl']);
        $this->assertSame('PT48M32S', $data['duration']);
    }

    public function testTheHomePageWebSiteNoLongerCarriesTheRetiredSearchAction(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Service/StructuredData.php');
        $this->assertTrue(!str_contains($source, "'potentialAction'"), 'Google retired the sitelinks search box in 2024');
    }

    public function testSameAsIsLimitedToAuthorityIRIsInTheirCanonicalForm(): void
    {
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

        $this->assertSame([
            'http://www.wikidata.org/entity/Q1189283',
            'https://d-nb.info/gnd/4163233-2',
            'https://orcid.org/0000-0003-0959-2092',
            'http://id.loc.gov/authorities/subjects/sh85071763',
            'https://sws.geonames.org/2950159/',
            'https://ror.org/0234wmv40',
            'http://viaf.org/viaf/12345',
            'https://isni.org/isni/000000012146438X',
        ], $data['sameAs']);
    }

    public function testALinkedRecordIsTypedAsItsOwnPageTypesItWhateverItsRole(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $builder = new StructuredData($config['dre_seo']['structured_data']['template_types']);
        $project = new Resource('Karakul Circulations', 5, 'Project', [], null, 1794, 'foaf:Project');
        $item = new ItemRepresentation('Alles was irgendwie nützt', 10, null, [
            'dcterms:isPartOf' => [new Value(resource: $project)],
            'frapo:isFundedBy' => [new Value(resource: new Resource('EXC 2052', 2))],
            'dcterms:subject' => [new Value(resource: new Resource('Germany', 3))],
        ]);

        $data = $builder->forResource($item, new SiteRepresentation(), null, null);

        $this->assertSame('ResearchProject', $data['isPartOf']['@type'], 'the project page says ResearchProject');
        $this->assertSame('Organization', $data['funder'][0]['@type']);
        $chapter = new Resource('A chapter', 14, null, [
            'dcterms:isPartOf' => [new Value(resource: new Resource('The book', 15))],
        ]);
        $this->assertSame('Book', $builder->forResource($chapter, new SiteRepresentation(), null, null)['isPartOf']['@type']);
        $literal = new Resource('A chapter', 14, null, ['dcterms:isPartOf' => [new Value('A book known by name')]]);
        $this->assertSame('Book', $builder->forResource($literal, new SiteRepresentation(), null, null)['isPartOf']['@type'], 'a name alone takes its role type');
    }

    public function testDatesAreISO8601AndAVideoFallsBackToItsCreationDateForUploadDate(): void
    {
        $builder = new StructuredData([22 => 'VideoObject', 15 => 'Book']);
        $book = new Resource('A book', 15, null, [
            'dcterms:issued' => [new Value('7 December 2018', rawValue: '2018-12-07')],
        ]);
        $undated = new Resource('A book', 15, null, ['dcterms:date' => [new Value('undated')]]);
        $video = new Resource('A video', 22, null, ['dcterms:created' => [new Value('1905-07-08', rawValue: '1905-07-08')]]);

        $this->assertSame('2018-12-07', $builder->forResource($book, new SiteRepresentation(), null, null)['datePublished']);
        $this->assertTrue(!isset($builder->forResource($undated, new SiteRepresentation(), null, null)['datePublished']), 'no year, no date');
        $videoData = $builder->forResource($video, new SiteRepresentation(), null, null);
        $this->assertSame('1905-07-08', $videoData['uploadDate']);
        $this->assertSame('1905-07-08', $videoData['dateCreated']);
    }

    public function testLanguagesAreBCP47TagsFromTheLanguageRecordCode(): void
    {
        $builder = new StructuredData([15 => 'Book']);
        $book = new Resource('A book', 15, null, ['dcterms:language' => [
            new Value(resource: new Resource('German', 6, null, ['dcterms:alternative' => [new Value('deu')]])),
            new Value(resource: new Resource('Nigerian Pidgin', 6, null, ['dcterms:alternative' => [new Value('pcm')]])),
            new Value(resource: new Resource('Dholuo', 6, null, ['dcterms:alternative' => [new Value('dholuo')]])),
        ]]);

        $this->assertSame(['de', 'pcm'], $builder->forResource($book, new SiteRepresentation(), null, null)['inLanguage']);
    }

    public function testALinkedLicenceResolvesToItsURLElseIsNamedUnderItsResourceURI(): void
    {
        $identity = new LinkedDataIdentity(static fn () => static fn (Resource $r): array => ['uri' => 'https://example.test/resource/' . $r->id()]);
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
        $this->assertSame('https://creativecommons.org/licenses/by-nc-sa/4.0/', $builder->forResource($licensed($byLabel), $site, null, null)['license']);
        $this->assertSame('https://rightsstatements.org/vocab/InC/1.0/', $builder->forResource($licensed($byUrl), $site, null, null)['license']);
        $this->assertSame(
            ['@type' => 'CreativeWork', '@id' => 'https://example.test/resource/10299', 'name' => 'Custom terms'],
            $builder->forResource($licensed($unknown), $site, null, null)['license']
        );
        $this->assertSame('https://creativecommons.org/licenses/by/3.0/', $builder->forResource($literal, $site, null, null)['license']);
        $this->assertSame('https://creativecommons.org/publicdomain/zero/1.0/', Licence::urlForLabel('CC0 1.0 Universal'));
        $this->assertSame(
            'https://creativecommons.org/licenses/by-nc-nd/4.0/',
            Licence::urlForLabel('Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International')
        );
        $this->assertSame(null, Licence::urlForLabel('CC-NC-4.0'));
    }

    public function testAResearchItemStatesItsConditionsOfAccess(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $builder = new StructuredData([10 => 'CreativeWork', 5 => 'ResearchProject'], 'CreativeWork', null, null, [
            'access_free' => $config['dre_seo']['structured_data']['access_free'],
            'access_statement' => static fn (): string => 'Ask the project.',
        ]);
        $project = new Resource('A project', 5, 'Project', [], null, 1794, 'foaf:Project');
        $researchItem = static fn (string ...$rights): ItemRepresentation => new ItemRepresentation('Recording', 10, null, [
            'dcterms:isPartOf' => [new Value(resource: $project)],
            'dcterms:accessRights' => array_map(static fn (string $right): Value => new Value(resource: new Resource($right, 6)), $rights),
        ]);
        $site = new SiteRepresentation();

        $noRawData = $builder->forResource($researchItem('No Raw Data'), $site, null, null);
        $this->assertSame(['No Raw Data', 'Ask the project.'], $noRawData['conditionsOfAccess']);
        $this->assertTrue(!isset($noRawData['isAccessibleForFree']), 'undecidable: left out');
        $this->assertSame(true, $builder->forResource($researchItem('Public'), $site, null, null)['isAccessibleForFree']);
        $this->assertSame(false, $builder->forResource($researchItem('Group'), $site, null, null)['isAccessibleForFree']);
        $this->assertTrue(!isset($builder->forResource($researchItem('Public', 'Group'), $site, null, null)['isAccessibleForFree']), 'conflicting');

        $publication = new ItemRepresentation('A book', 15, null, [
            'dcterms:isPartOf' => [new Value(resource: new Resource('A series', 29))],
        ]);
        $this->assertTrue(!isset($builder->forResource($publication, $site, null, null)['conditionsOfAccess']), 'not a research item');
    }

    public function testTheCatalogueIsADatasetWithADescriptionGoogleAccepts(): void
    {
        $site = new SiteRepresentation('amira', 'AMIRA', new SitePageRepresentation('home'));
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

        $data = $builder->dataset(new PhpRenderer(), $site, $dataset);

        $this->assertSame('Dataset', $data['@type']);
        $this->assertSame('https://example.test/linked-data/void#dataset', $data['@id']);
        $this->assertTrue(mb_strlen($data['description']) >= StructuredData::DATASET_DESCRIPTION_MIN, 'at least 50 characters');
        $this->assertSame('https://example.test/s/amira/page/home', $data['url']);
        $this->assertSame('http://www.wikidata.org/entity/Q141717295', $data['publisher']['sameAs']);
        $this->assertSame($data['publisher'], $data['creator']);
        $this->assertSame(
            ['@type' => 'DataDownload', 'name' => 'N-Triples dump (gzip)', 'contentUrl' => 'https://example.test/linked-data/dump.nt.gz', 'encodingFormat' => 'application/n-triples', 'contentSize' => '1.9 MB'],
            $data['distribution'][0]
        );
        $this->assertSame(['@type' => 'DataCatalog', 'name' => 'AMIRA', 'url' => 'https://example.test/s/amira/page/home'], $data['includedInDataCatalog']);
        $this->assertSame(null, $builder->dataset(new PhpRenderer(), $site, []), 'nothing to describe');
    }

    public function testAnItemSetPageIsACollectionPageAboutTheItemSet(): void
    {
        $identity = new LinkedDataIdentity(static fn () => static fn (Resource $r): array => ['uri' => 'https://example.test/resource/' . $r->id()]);
        $builder = new StructuredData([], 'CreativeWork', null, $identity);
        $itemSet = new Resource('Publications', null, null, [], null, 29918);

        $data = $builder->collectionPage(new PhpRenderer(), $itemSet, new SiteRepresentation(), 'https://example.test/s/amira/item-set/29918', 'The cluster bibliography.');

        $this->assertSame('https://example.test/s/amira/item-set/29918', $data['@id']);
        $this->assertSame(['@type' => 'Collection', '@id' => 'https://example.test/resource/29918', 'name' => 'Publications'], $data['mainEntity']);
    }

    public function testTheBreadcrumbPlacesAnItemInItsItemSetPreferringOneTheSiteUses(): void
    {
        $builder = new StructuredData([]);
        $view = new PhpRenderer();
        $site = new SiteRepresentation('amira', 'AMIRA', new SitePageRepresentation());
        $thesis = new ItemRepresentation('Das Archiv als Heimat', idValue: 41980);
        $thesis->itemSetList = [
            7 => new ItemSetRepresentation('Unused set', publicUrl: 'https://example.test/s/amira/item-set/7', idValue: 7),
            29918 => new ItemSetRepresentation('Publications', publicUrl: 'https://example.test/s/amira/item-set/29918', idValue: 29918),
        ];
        $site->siteItemSetIds = [29918];
        $canonical = 'https://example.test/s/amira/item/41980';

        $this->assertSame([
            ['name' => 'AMIRA', 'url' => 'https://example.test/s/amira/page/home'],
            ['name' => 'Publications', 'url' => 'https://example.test/s/amira/item-set/29918'],
            ['name' => 'Das Archiv als Heimat', 'url' => $canonical],
        ], $builder->trail($view, $thesis, $site, $canonical));

        $list = $builder->breadcrumb($view, $thesis, $site, $canonical);
        $this->assertSame('BreadcrumbList', $list['@type']);
        $this->assertSame([1, 2, 3], array_column($list['itemListElement'], 'position'));
        $this->assertSame('https://example.test/s/amira/item-set/29918', $list['itemListElement'][1]['item']);

        // No item set the site uses: the item's first.
        $site->siteItemSetIds = [];
        $this->assertSame('Unused set', $builder->trail($view, $thesis, $site, $canonical)[1]['name']);
    }

    public function testTheBreadcrumbPlacesAMediaInItsItemAndStopsAtTheRecordOtherwise(): void
    {
        $builder = new StructuredData([]);
        $view = new PhpRenderer();
        $site = new SiteRepresentation('amira', 'AMIRA', new SitePageRepresentation());

        $media = new MediaRepresentation('Scan 1', idValue: 5);
        $media->parent = new ItemRepresentation('A letter', publicUrl: 'https://example.test/s/amira/item/4', idValue: 4);
        $this->assertSame(
            ['AMIRA', 'A letter', 'Scan 1'],
            array_column($builder->trail($view, $media, $site, 'https://example.test/s/amira/media/5'), 'name')
        );

        $unsorted = new ItemRepresentation('Unsorted', idValue: 9);
        $this->assertSame(
            ['AMIRA', 'Unsorted'],
            array_column($builder->trail($view, $unsorted, $site, 'https://example.test/s/amira/item/9'), 'name'),
            'an item in no item set'
        );
        $itemSet = new ItemSetRepresentation('Publications', idValue: 29918);
        $this->assertSame(2, count($builder->trail($view, $itemSet, $site, 'https://example.test/s/amira/item-set/29918')));
        $this->assertSame(null, $builder->trail($view, $unsorted, $site, null), 'no page in the site');
    }

    /** A record without a template takes the default type, without a null array key (deprecated in PHP 8.5). */
    public function testARecordWithoutATemplateTakesTheDefaultTypeQuietly(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_DEPRECATED);
        try {
            $type = (new StructuredData([22 => 'VideoObject'], 'CreativeWork'))
                ->typeFor(new MediaRepresentation('Scan 1'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame('CreativeWork', $type);
        $this->assertSame([], $deprecations);
    }
}
