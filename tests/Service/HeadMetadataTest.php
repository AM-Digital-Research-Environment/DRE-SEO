<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\ImageDimensions;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\ResourceSummary;
use DRESeo\Service\SearchPage;
use DRESeo\Service\Signposting;
use DRESeo\Service\SiteHome;
use DRESeo\Service\StructuredData;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The <head> HeadMetadata writes — title, canonical, robots, share image and
 * the JSON-LD blocks — through the recording renderer in tests/Shim.
 */
final class HeadMetadataTest extends TestCase
{
    /** @param array<string,mixed> $settings */
    private static function headMetadataService(
        array $settings = [],
        ?LinkedDataIdentity $identity = null,
        ?ImageDimensions $dimensions = null
    ): HeadMetadata {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];
        $kinds = new CitationKindMap($citation['template_kinds'], $citation['default_kind']);
        $identity ??= new LinkedDataIdentity();
        $structuredData = new StructuredData(
            $config['dre_seo']['structured_data']['template_types'],
            'CreativeWork',
            $kinds,
            $identity
        );
        $store = new Settings();
        foreach ($settings as $key => $value) {
            $store->set($key, $value);
        }

        return new HeadMetadata(
            $store,
            $structuredData,
            new CitationMeta($kinds, new CitationData($kinds)),
            $kinds,
            new ResourceSummary($kinds),
            new Signposting($structuredData, $identity),
            $identity,
            $dimensions,
        );
    }

    private static function amiraSite(): SiteRepresentation
    {
        return new SiteRepresentation('amira', 'Africa Multiple Interactive Research Atlas (AMIRA)', new SitePageRepresentation('home', 'Home', 10));
    }

    public function testTheHomePageIsSelfCanonicalAtItsPageURLAndNamedAfterTheSite(): void
    {
        $site = self::amiraSite();
        $view = new PhpRenderer();
        $head = self::headMetadataService();

        $head->applyPage($view, $site->homepage(), $site, [], true);

        $home = 'https://example.test/s/amira/page/home';
        $this->assertSame([['rel' => 'canonical', 'href' => $home]], $view->links('canonical'));
        $this->assertSame([$home], $view->meta('og:url'));
        $this->assertSame(['Africa Multiple Interactive Research Atlas (AMIRA)'], $view->meta('og:title'), 'not "Home"');
        $webSite = $view->jsonLd()[0];
        $this->assertSame('WebSite', $webSite['@type']);
        $this->assertSame($home, $webSite['url'], 'the WebSite is the home page, not a redirecting site root');
    }

    public function testWithoutAConfiguredHomepageTheHomeIsTheFirstLinkedPageAsOmekaRedirects(): void
    {
        $about = new SitePageRepresentation('about', 'About', 12);
        $site = new SiteRepresentation('amira', 'AMIRA', null, [$about]);

        $this->assertSame('https://example.test/s/amira/page/about', SiteHome::url($site));
        $this->assertSame(12, SiteHome::pageId($site));
        $this->assertSame(null, SiteHome::url(new SiteRepresentation()));
    }

    public function testAnOrdinaryPageKeepsItsOwnTitleAndCanonical(): void
    {
        $site = self::amiraSite();
        $view = new PhpRenderer('https://example.test/s/amira/page/team');

        self::headMetadataService()->applyPage($view, new SitePageRepresentation('team', 'Team', 11), $site, [], false);

        $this->assertSame('https://example.test/s/amira/page/team', $view->links('canonical')[0]['href']);
        $this->assertSame(['Team'], $view->meta('og:title'));
        $this->assertSame([], $view->jsonLd(), 'no WebSite node off the home page');
    }

    public function testTheHomePageCarriesTheCatalogueDatasetWhenDRELinkedDataDescribesIt(): void
    {
        $helper = new class {
            /**
             * @param mixed $resource
             * @return array<string,mixed>
             */
            public function __invoke($resource): array
            {
                return [];
            }

            /** @return array<string,mixed> */
            public function dataset(): array
            {
                return [
                    'uri' => 'https://example.test/linked-data/void#dataset',
                    'title' => 'AMIRA',
                    'description' => 'Every public record of the Africa Multiple Interactive Research Atlas, as Linked Open Data.',
                ];
            }
        };
        $site = self::amiraSite();

        $view = new PhpRenderer();
        self::headMetadataService([], new LinkedDataIdentity(static fn () => $helper))->applyPage($view, $site->homepage(), $site, [], true);
        $types = array_column($view->jsonLd(), '@type');
        $this->assertSame(['WebSite', 'Dataset'], $types);
        $this->assertTrue(!str_contains($view->head['script'][0], "\n"), 'compact JSON');

        $off = new PhpRenderer();
        self::headMetadataService([], new LinkedDataIdentity(static fn () => $helper))->applyPage($off, $site->homepage(), $site, ['jsonld' => false], true);
        $this->assertSame([], $off->jsonLd(), 'the page override switches JSON-LD off');

        $old = new PhpRenderer();
        self::headMetadataService([], new LinkedDataIdentity(static fn () => static fn ($r): array => []))->applyPage($old, $site->homepage(), $site, [], true);
        $this->assertSame(['WebSite'], array_column($old->jsonLd(), '@type'), 'DRE Linked Data 0.2.0: no dataset()');
    }

    public function testWithALandingPageDRELinkedData031TheDatasetMovesToThatPage(): void
    {
        $helper = new class {
            /**
             * @param mixed $resource
             * @return array<string,mixed>
             */
            public function __invoke($resource): array
            {
                return [];
            }

            /** @return array<string,mixed> */
            public function dataset(): array
            {
                return [
                    'uri' => 'https://example.test/linked-data/void#dataset',
                    'landingPage' => 'https://example.test/s/amira/page/linked-open-data',
                    'title' => 'AMIRA',
                    'description' => 'Every public record of the Africa Multiple Interactive Research Atlas, as Linked Open Data.',
                ];
            }
        };
        $site = self::amiraSite();
        $identity = static fn () => new LinkedDataIdentity(static fn () => $helper);

        $home = new PhpRenderer();
        self::headMetadataService([], $identity())->applyPage($home, $site->homepage(), $site, [], true);
        $this->assertSame(['WebSite'], array_column($home->jsonLd(), '@type'), 'the home page keeps only the WebSite');

        $lod = new PhpRenderer('https://example.test/s/amira/page/linked-open-data');
        self::headMetadataService([], $identity())->applyPage($lod, new SitePageRepresentation('linked-open-data', 'Linked open data', 12), $site, [], false);
        $nodes = $lod->jsonLd();
        $this->assertSame(['Dataset'], array_column($nodes, '@type'));
        $this->assertSame('https://example.test/s/amira/page/linked-open-data', $nodes[0]['url']);

        $team = new PhpRenderer('https://example.test/s/amira/page/team');
        self::headMetadataService([], $identity())->applyPage($team, new SitePageRepresentation('team', 'Team', 11), $site, [], false);
        $this->assertSame([], $team->jsonLd(), 'any other page carries no Dataset');
    }

    public function testSearchResultsAndQueryStringVariantsAreNoindexFollowCanonicalsDropTheQuery(): void
    {
        $site = self::amiraSite();
        $settings = ['dre_seo_noindex_browse' => '1'];

        // DRE Search's results page: noindex even without a query.
        $search = new PhpRenderer('https://example.test/s/amira/dre-search');
        self::headMetadataService($settings)->applyGlobals($search, $site, true);
        $this->assertSame(['noindex, follow'], $search->meta('robots'));
        $this->assertSame('https://example.test/s/amira/dre-search', $search->links('canonical')[0]['href']);

        // Any other page with a query string: noindex, canonical without it.
        $variant = new PhpRenderer('https://example.test/s/amira/some-module?sort=title&utm_source=newsletter');
        self::headMetadataService($settings)->applyGlobals($variant, $site);
        $this->assertSame(['noindex, follow'], $variant->meta('robots'));
        $this->assertSame('https://example.test/s/amira/some-module', $variant->links('canonical')[0]['href']);
        $this->assertSame(['https://example.test/s/amira/some-module'], $variant->meta('og:url'));

        // Tracking parameters alone make no variant.
        $tracked = new PhpRenderer('https://example.test/s/amira/some-module?utm_source=x&fbclid=y');
        self::headMetadataService($settings)->applyGlobals($tracked, $site);
        $this->assertSame(['max-image-preview:large'], $tracked->meta('robots'));

        // The switch off: nothing is noindexed.
        $off = new PhpRenderer('https://example.test/s/amira/some-module?sort=title');
        self::headMetadataService([])->applyGlobals($off, $site, true);
        $this->assertSame(['max-image-preview:large'], $off->meta('robots'));
    }

    public function testARecordPagingThroughItsConnectionListsStaysIndexable(): void
    {
        $site = self::amiraSite();
        $settings = ['dre_seo_noindex_browse' => '1'];
        $person = new ItemRepresentation('Example, Ada', 4, 'Person', [], 'https://example.test/s/amira/item/42', 42);

        $paged = new PhpRenderer('https://example.test/s/amira/item/42?lr_property=items:12&lr_page=3');
        $head = self::headMetadataService($settings);
        $head->applyResource($paged, $person, $site);
        $head->applyGlobals($paged, $site);
        $this->assertSame(['max-image-preview:large'], $paged->meta('robots'));
        $this->assertSame('https://example.test/s/amira/item/42', $paged->links('canonical')[0]['href']);

        $searched = new PhpRenderer('https://example.test/s/amira/item/42?lr_q=karakul');
        $head = self::headMetadataService($settings);
        $head->applyResource($searched, $person, $site);
        $head->applyGlobals($searched, $site);
        $this->assertSame(['noindex, follow'], $searched->meta('robots'));
    }

    public function testABrowseCanonicalKeepsItsFacetsButNotItsTrackingParameters(): void
    {
        $view = new PhpRenderer('https://example.test/s/amira/item?fulltext_search=gender&utm_campaign=x');

        self::headMetadataService(['dre_seo_noindex_browse' => '1'])->applyBrowse($view, self::amiraSite());

        $this->assertSame('https://example.test/s/amira/item?fulltext_search=gender', $view->links('canonical')[0]['href']);
        $this->assertSame(['noindex, follow'], $view->meta('robots'));
    }

    public function testAnItemSetPageStatesItsBreadcrumbOnItsCleanURLOnly(): void
    {
        $itemSet = new ItemSetRepresentation('Publications', publicUrl: 'https://example.test/s/amira/item-set/29918', idValue: 29918);

        $clean = new PhpRenderer('https://example.test/s/amira/item-set/29918');
        self::headMetadataService()->applyBrowse($clean, self::amiraSite(), $itemSet);
        [$page, $breadcrumb] = $clean->jsonLd();
        $this->assertSame('CollectionPage', $page['@type']);
        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
        $this->assertSame(
            ['https://example.test/s/amira/page/home', 'https://example.test/s/amira/item-set/29918'],
            array_column($breadcrumb['itemListElement'], 'item')
        );

        $paged = new PhpRenderer('https://example.test/s/amira/item-set/29918?page=2');
        self::headMetadataService()->applyBrowse($paged, self::amiraSite(), $itemSet);
        $this->assertSame([], $paged->jsonLd());
    }

    public function testSearchPagesAreRecognisedByTheirRoute(): void
    {
        $this->assertTrue(SearchPage::matches('site/resource', 'Omeka\Controller\Site\Index', 'search'));
        $this->assertTrue(SearchPage::matches('site/resource', 'Omeka\Controller\Site\Item', 'search'));
        $this->assertTrue(SearchPage::matches('site/dre-search', 'DRESearch\Controller\SearchController', 'results'));
        $this->assertTrue(!SearchPage::matches('site/resource', 'Omeka\Controller\Site\Item', 'browse'));
        $this->assertTrue(!SearchPage::matches('admin/default', 'Omeka\Controller\Admin\Item', 'search'));
    }

    public function testALocalShareImageDeclaresItsSizeARemoteOneDoesNot(): void
    {
        $files = sys_get_temp_dir() . '/dre-seo-files-' . bin2hex(random_bytes(6));
        mkdir($files . '/large', 0777, true);
        // A PNG header is all getimagesize() reads; no GD needed.
        $ihdr = 'IHDR' . pack('NNCCCCC', 800, 450, 8, 2, 0, 0, 0);
        file_put_contents(
            $files . '/large/abc123.png',
            "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr))
        );
        $dimensions = new ImageDimensions($files);

        try {
            $local = new PhpRenderer();
            $head = self::headMetadataService([], null, $dimensions);
            $head->setImage($local, 'https://example.test/files/large/abc123.png', 'AMIRA');
            $this->assertSame(['800'], $local->meta('og:image:width'));
            $this->assertSame(['450'], $local->meta('og:image:height'));
            $this->assertSame(['AMIRA'], $local->meta('og:image:alt'));

            $remote = new PhpRenderer();
            $head->setImage($remote, 'https://i.ytimg.com/vi/abc/hqdefault.jpg');
            $this->assertSame([], $remote->meta('og:image:width'));
            $this->assertSame(null, $dimensions->of('https://example.test/files/large/..%2F..%2Fsecret.png'));
        } finally {
            @unlink($files . '/large/abc123.png');
            @rmdir($files . '/large');
            @rmdir($files);
        }
    }

    public function testAResearchItemPageOneIdentityNoHighwireTypedProjectAccessConditions(): void
    {
        $identity = new LinkedDataIdentity(static fn () => static fn ($resource): array => [
            'uri' => 'https://example.test/resource/' . $resource->id(),
            'downloads' => [['label' => 'Turtle', 'type' => 'text/turtle', 'url' => 'https://example.test/resource/' . $resource->id() . '.ttl']],
        ]);
        $project = new ItemRepresentation('Karakul Circulations', 5, 'Project', [], 'https://example.test/s/amira/item/1794', 1794, 'foaf:Project');
        $video = new ItemRepresentation('Alles was irgendwie nützt', 10, null, [
            'dcterms:title' => [new ValueRepresentation('Alles was irgendwie nützt')],
            'dcterms:created' => [new ValueRepresentation('1905-07-08')],
            'dcterms:isPartOf' => [new ValueRepresentation('', $project)],
            'dcterms:accessRights' => [new ValueRepresentation('', new ItemRepresentation('No Raw Data', 6))],
        ], 'https://example.test/s/amira/item/8698', 8698);
        $view = new PhpRenderer('https://example.test/s/amira/item/8698');

        self::headMetadataService([], $identity)->applyResource($view, $video, self::amiraSite());

        $this->assertSame('https://example.test/s/amira/item/8698', $view->links('canonical')[0]['href']);
        $this->assertSame(['https://example.test/s/amira/item/8698'], $view->meta('og:url'));
        $this->assertSame(['https://example.test/resource/8698'], $view->meta('DC.identifier'));
        $this->assertSame('https://example.test/resource/8698', $view->links('cite-as')[0]['href']);
        $this->assertSame([], array_values(array_filter(
            array_column($view->head['meta'], 1),
            static fn (string $name): bool => str_starts_with($name, 'citation_')
        )), 'no Highwire tags on a research item without an author');
        [$record, $breadcrumb] = $view->jsonLd();
        $this->assertSame('https://example.test/resource/8698', $record['@id']);
        $this->assertSame('https://example.test/s/amira/item/8698', $record['url']);
        $this->assertSame('ResearchProject', $record['isPartOf']['@type']);
        $this->assertSame('https://example.test/resource/1794', $record['isPartOf']['@id']);
        $this->assertSame('1905-07-08', $record['dateCreated']);
        $this->assertSame('No Raw Data', $record['conditionsOfAccess'], 'no statement configured here');
        $this->assertSame('https://example.test/s/amira/page/home', $breadcrumb['itemListElement'][0]['item']);
    }
}
