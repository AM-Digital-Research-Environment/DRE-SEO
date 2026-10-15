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
function headMetadataService(array $settings = [], ?LinkedDataIdentity $identity = null): HeadMetadata
{
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
