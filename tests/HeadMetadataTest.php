<?php
declare(strict_types=1);

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\ResourceSummary;
use DRESeo\Service\Signposting;
use DRESeo\Service\StructuredData;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/CitationKindMap.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/CitationData.php';
require_once __DIR__ . '/../src/Service/CitationMeta.php';
require_once __DIR__ . '/../src/Service/LinkedDataIdentity.php';
require_once __DIR__ . '/../src/Service/ResourceSummary.php';
require_once __DIR__ . '/../src/Service/SettingFlag.php';
require_once __DIR__ . '/../src/Service/SiteHome.php';
require_once __DIR__ . '/../src/Service/StructuredData.php';
require_once __DIR__ . '/../src/Service/Signposting.php';
require_once __DIR__ . '/../src/Service/ViewLocale.php';
require_once __DIR__ . '/../src/Service/HeadMetadata.php';

/** @param array<string,mixed> $settings */
function headMetadataService(
    array $settings = [],
    ?LinkedDataIdentity $identity = null,
    ?DRESeo\Service\ImageDimensions $dimensions = null
): HeadMetadata {
    $config = require __DIR__ . '/../config/module.config.php';
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

function amiraSite(): SiteRepresentation
{
    return new SiteRepresentation('amira', 'Africa Multiple Interactive Research Atlas (AMIRA)', new SitePageRepresentation('home', 'Home', 10));
}

test('the home page is self-canonical at its page URL and named after the site', function (): void {
    $site = amiraSite();
    $view = new PhpRenderer();
    $head = headMetadataService();

    $head->applyPage($view, $site->homepage(), $site, [], true);

    $home = 'https://example.test/s/amira/page/home';
    assertSameValue([['rel' => 'canonical', 'href' => $home]], $view->links('canonical'));
    assertSameValue([$home], $view->meta('og:url'));
    assertSameValue(['Africa Multiple Interactive Research Atlas (AMIRA)'], $view->meta('og:title'), 'not "Home"');
    $webSite = $view->jsonLd()[0];
    assertSameValue('WebSite', $webSite['@type']);
    assertSameValue($home, $webSite['url'], 'the WebSite is the home page, not a redirecting site root');
});

test('without a configured homepage the home is the first linked page, as Omeka redirects', function (): void {
    $about = new SitePageRepresentation('about', 'About', 12);
    $site = new SiteRepresentation('amira', 'AMIRA', null, [$about]);

    assertSameValue('https://example.test/s/amira/page/about', DRESeo\Service\SiteHome::url($site));
    assertSameValue(12, DRESeo\Service\SiteHome::pageId($site));
    assertSameValue(null, DRESeo\Service\SiteHome::url(new SiteRepresentation()));
});

test('an ordinary page keeps its own title and canonical', function (): void {
    $site = amiraSite();
    $view = new PhpRenderer('https://example.test/s/amira/page/team');

    headMetadataService()->applyPage($view, new SitePageRepresentation('team', 'Team', 11), $site, [], false);

    assertSameValue('https://example.test/s/amira/page/team', $view->links('canonical')[0]['href']);
    assertSameValue(['Team'], $view->meta('og:title'));
    assertSameValue([], $view->jsonLd(), 'no WebSite node off the home page');
});

test('the home page carries the catalogue Dataset when DRE Linked Data describes it', function (): void {
    $helper = new class {
        public function __invoke($resource): array
        {
            return [];
        }

        public function dataset(): array
        {
            return [
                'uri' => 'https://example.test/linked-data/void#dataset',
                'title' => 'AMIRA',
                'description' => 'Every public record of the Africa Multiple Interactive Research Atlas, as Linked Open Data.',
            ];
        }
    };
    $site = amiraSite();

    $view = new PhpRenderer();
    headMetadataService([], new LinkedDataIdentity(static fn () => $helper))->applyPage($view, $site->homepage(), $site, [], true);
    $types = array_column($view->jsonLd(), '@type');
    assertSameValue(['WebSite', 'Dataset'], $types);
    assertTrue(!str_contains($view->head['script'][0], "\n"), 'compact JSON');

    $off = new PhpRenderer();
    headMetadataService([], new LinkedDataIdentity(static fn () => $helper))->applyPage($off, $site->homepage(), $site, ['jsonld' => false], true);
    assertSameValue([], $off->jsonLd(), 'the page override switches JSON-LD off');

    $old = new PhpRenderer();
    headMetadataService([], new LinkedDataIdentity(static fn () => static fn ($r): array => []))->applyPage($old, $site->homepage(), $site, [], true);
    assertSameValue(['WebSite'], array_column($old->jsonLd(), '@type'), 'DRE Linked Data 0.2.0: no dataset()');
});

test('search results and query-string variants are noindex, follow; canonicals drop the query', function (): void {
    $site = amiraSite();
    $settings = ['dre_seo_noindex_browse' => '1'];

    // DRE Search's results page: noindex even without a query.
    $search = new PhpRenderer('https://example.test/s/amira/dre-search');
    headMetadataService($settings)->applyGlobals($search, $site, true);
    assertSameValue(['noindex, follow'], $search->meta('robots'));
    assertSameValue('https://example.test/s/amira/dre-search', $search->links('canonical')[0]['href']);

    // Any other page with a query string: noindex, canonical without it.
    $variant = new PhpRenderer('https://example.test/s/amira/some-module?sort=title&utm_source=newsletter');
    headMetadataService($settings)->applyGlobals($variant, $site);
    assertSameValue(['noindex, follow'], $variant->meta('robots'));
    assertSameValue('https://example.test/s/amira/some-module', $variant->links('canonical')[0]['href']);
    assertSameValue(['https://example.test/s/amira/some-module'], $variant->meta('og:url'));

    // Tracking parameters alone make no variant.
    $tracked = new PhpRenderer('https://example.test/s/amira/some-module?utm_source=x&fbclid=y');
    headMetadataService($settings)->applyGlobals($tracked, $site);
    assertSameValue(['max-image-preview:large'], $tracked->meta('robots'));

    // The switch off: nothing is noindexed.
    $off = new PhpRenderer('https://example.test/s/amira/some-module?sort=title');
    headMetadataService([])->applyGlobals($off, $site, true);
    assertSameValue(['max-image-preview:large'], $off->meta('robots'));
});

test('a record paging through its connection lists stays indexable', function (): void {
    $site = amiraSite();
    $settings = ['dre_seo_noindex_browse' => '1'];
    $person = new Omeka\Api\Representation\ItemRepresentation('Example, Ada', 4, 'Person', [], 'https://example.test/s/amira/item/42', 42);

    $paged = new PhpRenderer('https://example.test/s/amira/item/42?lr_property=items:12&lr_page=3');
    $head = headMetadataService($settings);
    $head->applyResource($paged, $person, $site);
    $head->applyGlobals($paged, $site);
    assertSameValue(['max-image-preview:large'], $paged->meta('robots'));
    assertSameValue('https://example.test/s/amira/item/42', $paged->links('canonical')[0]['href']);

    $searched = new PhpRenderer('https://example.test/s/amira/item/42?lr_q=karakul');
    $head = headMetadataService($settings);
    $head->applyResource($searched, $person, $site);
    $head->applyGlobals($searched, $site);
    assertSameValue(['noindex, follow'], $searched->meta('robots'));
});

test('a browse canonical keeps its facets but not its tracking parameters', function (): void {
    $view = new PhpRenderer('https://example.test/s/amira/item?fulltext_search=gender&utm_campaign=x');

    headMetadataService(['dre_seo_noindex_browse' => '1'])->applyBrowse($view, amiraSite());

    assertSameValue('https://example.test/s/amira/item?fulltext_search=gender', $view->links('canonical')[0]['href']);
    assertSameValue(['noindex, follow'], $view->meta('robots'));
});

test('search pages are recognised by their route', function (): void {
    assertTrue(DRESeo\Service\SearchPage::matches('site/resource', 'Omeka\Controller\Site\Index', 'search'));
    assertTrue(DRESeo\Service\SearchPage::matches('site/resource', 'Omeka\Controller\Site\Item', 'search'));
    assertTrue(DRESeo\Service\SearchPage::matches('site/dre-search', 'DRESearch\Controller\SearchController', 'results'));
    assertTrue(!DRESeo\Service\SearchPage::matches('site/resource', 'Omeka\Controller\Site\Item', 'browse'));
    assertTrue(!DRESeo\Service\SearchPage::matches('admin/default', 'Omeka\Controller\Admin\Item', 'search'));
});

test('a local share image declares its size; a remote one does not', function (): void {
    $files = sys_get_temp_dir() . '/dre-seo-files-' . bin2hex(random_bytes(6));
    mkdir($files . '/large', 0777, true);
    // A PNG header is all getimagesize() reads; no GD needed.
    $ihdr = 'IHDR' . pack('NNCCCCC', 800, 450, 8, 2, 0, 0, 0);
    file_put_contents(
        $files . '/large/abc123.png',
        "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr))
    );
    $dimensions = new DRESeo\Service\ImageDimensions($files);

    try {
        $local = new PhpRenderer();
        $head = headMetadataService([], null, $dimensions);
        $head->setImage($local, 'https://example.test/files/large/abc123.png', 'AMIRA');
        assertSameValue(['800'], $local->meta('og:image:width'));
        assertSameValue(['450'], $local->meta('og:image:height'));
        assertSameValue(['AMIRA'], $local->meta('og:image:alt'));

        $remote = new PhpRenderer();
        $head->setImage($remote, 'https://i.ytimg.com/vi/abc/hqdefault.jpg');
        assertSameValue([], $remote->meta('og:image:width'));
        assertSameValue(null, $dimensions->of('https://example.test/files/large/..%2F..%2Fsecret.png'));
    } finally {
        @unlink($files . '/large/abc123.png');
        @rmdir($files . '/large');
        @rmdir($files);
    }
});

test('a research item page: one identity, no Highwire, typed project, access conditions', function (): void {
    $identity = new LinkedDataIdentity(static fn () => static fn ($resource): array => [
        'uri' => 'https://example.test/resource/' . $resource->id(),
        'downloads' => [['label' => 'Turtle', 'type' => 'text/turtle', 'url' => 'https://example.test/resource/' . $resource->id() . '.ttl']],
    ]);
    $project = new Omeka\Api\Representation\ItemRepresentation('Karakul Circulations', 5, 'Project', [], 'https://example.test/s/amira/item/1794', 1794, 'foaf:Project');
    $video = new Omeka\Api\Representation\ItemRepresentation('Alles was irgendwie nützt', 10, null, [
        'dcterms:title' => [new Omeka\Api\Representation\ValueRepresentation('Alles was irgendwie nützt')],
        'dcterms:created' => [new Omeka\Api\Representation\ValueRepresentation('1905-07-08')],
        'dcterms:isPartOf' => [new Omeka\Api\Representation\ValueRepresentation('', $project)],
        'dcterms:accessRights' => [new Omeka\Api\Representation\ValueRepresentation('', new Omeka\Api\Representation\ItemRepresentation('No Raw Data', 6))],
    ], 'https://example.test/s/amira/item/8698', 8698);
    $view = new PhpRenderer('https://example.test/s/amira/item/8698');

    headMetadataService([], $identity)->applyResource($view, $video, amiraSite());

    assertSameValue('https://example.test/s/amira/item/8698', $view->links('canonical')[0]['href']);
    assertSameValue(['https://example.test/s/amira/item/8698'], $view->meta('og:url'));
    assertSameValue(['https://example.test/resource/8698'], $view->meta('DC.identifier'));
    assertSameValue('https://example.test/resource/8698', $view->links('cite-as')[0]['href']);
    assertSameValue([], array_values(array_filter(
        array_column($view->head['meta'], 1),
        static fn (string $name): bool => str_starts_with($name, 'citation_')
    )), 'no Highwire tags on a research item without an author');
    [$record, $breadcrumb] = $view->jsonLd();
    assertSameValue('https://example.test/resource/8698', $record['@id']);
    assertSameValue('https://example.test/s/amira/item/8698', $record['url']);
    assertSameValue('ResearchProject', $record['isPartOf']['@type']);
    assertSameValue('https://example.test/resource/1794', $record['isPartOf']['@id']);
    assertSameValue('1905-07-08', $record['dateCreated']);
    assertSameValue('No Raw Data', $record['conditionsOfAccess'], 'no statement configured here');
    assertSameValue('https://example.test/s/amira/page/home', $breadcrumb['itemListElement'][0]['item']);
});
