<?php
declare(strict_types=1);

// Omeka S 4.2.1's pinned DBAL/Laminas versions emit PHP 8.5 deprecation
// notices; keep the smoke test focused on module failures.
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * Omeka-backed integration smoke test.
 *
 * OMEKA_PATH must point at an unpacked official Omeka S release. The script
 * deliberately loads Omeka's own Composer runtime instead of adding framework
 * dependencies to this module.
 */

$moduleRoot = dirname(__DIR__, 2);
$omekaPath = rtrim((string) getenv('OMEKA_PATH'), '/\\');
$autoload = $omekaPath . '/vendor/autoload.php';
if ($omekaPath === '' || !is_readable($autoload)) {
    fwrite(STDERR, "ERROR: OMEKA_PATH must point at an unpacked Omeka S release.\n");
    exit(2);
}

if (!defined('OMEKA_PATH')) {
    define('OMEKA_PATH', $omekaPath);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('DRESeo\\', $moduleRoot . '/src/');
require_once $omekaPath . '/application/Module.php';
require_once $moduleRoot . '/Module.php';

final class SmokeSettings extends Omeka\Settings\Settings
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get($id, $default = null)
    {
        return $this->values[strtolower((string) $id)] ?? $default;
    }

    public function set($id, $value)
    {
        $this->values[strtolower((string) $id)] = $value;
    }

    public function delete($id)
    {
        unset($this->values[strtolower((string) $id)]);
    }
}

final class SmokeSiteSettings extends Omeka\Settings\SiteSettings
{
    private int $currentTarget = 0;

    /** @var array<int,array<string,mixed>> */
    private array $values = [];

    public function __construct()
    {
    }

    public function setTargetId($targetId)
    {
        $this->currentTarget = (int) $targetId;
    }

    public function get($id, $default = null, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        return $this->values[$target][strtolower((string) $id)] ?? $default;
    }

    public function set($id, $value, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        $this->values[$target][strtolower((string) $id)] = $value;
    }

    public function delete($id, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        unset($this->values[$target][strtolower((string) $id)]);
    }
}

final class SmokeSiteRepresentation extends Omeka\Api\Representation\SiteRepresentation
{
    public function __construct()
    {
    }

    public function id()
    {
        return 1;
    }

    public function slug()
    {
        return 'amira';
    }

    public function title()
    {
        return 'AMIRA Smoke';
    }

    public function homepage()
    {
        return null;
    }
}

final class SmokeCurrentSite extends Laminas\View\Helper\AbstractHelper
{
    public function __construct(private readonly SmokeSiteRepresentation $site)
    {
    }

    public function __invoke(): SmokeSiteRepresentation
    {
        return $this->site;
    }
}

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reflected(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

$cacheBase = sys_get_temp_dir() . '/dre-seo-omeka-smoke-' . bin2hex(random_bytes(6));
$generator = null;
$exitCode = 0;

try {
    smokeAssert(Omeka\Module::VERSION === '4.2.1', 'The smoke test must use Omeka S 4.2.1.');

    $module = new DRESeo\Module();
    $config = $module->getConfig();
    $config['file_store']['local']['base_path'] = $cacheBase;

    $connection = Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    foreach ([
        'CREATE TABLE resource (id INTEGER PRIMARY KEY, resource_type VARCHAR(255), is_public INTEGER, modified VARCHAR(32))',
        'CREATE TABLE item_site (item_id INTEGER, site_id INTEGER)',
        'CREATE TABLE site_item_set (item_set_id INTEGER, site_id INTEGER)',
        'CREATE TABLE site_page (id INTEGER PRIMARY KEY, site_id INTEGER, slug VARCHAR(255), is_public INTEGER, modified VARCHAR(32))',
        "INSERT INTO resource VALUES (1, 'Omeka\\Entity\\Item', 1, '2026-01-01 00:00:00')",
        "INSERT INTO resource VALUES (2, 'Omeka\\Entity\\Item', 1, NULL)",
        "INSERT INTO resource VALUES (3, 'Omeka\\Entity\\ItemSet', 1, '2026-01-02 00:00:00')",
        "INSERT INTO resource VALUES (4, 'Omeka\\Entity\\Item', 0, NULL)",
        'INSERT INTO item_site VALUES (1, 1)',
        'INSERT INTO item_site VALUES (2, 1)',
        'INSERT INTO item_site VALUES (4, 1)',
        'INSERT INTO site_item_set VALUES (3, 1)',
        "INSERT INTO site_page VALUES (10, 1, 'home', 1, '2026-01-03 00:00:00')",
        "INSERT INTO site_page VALUES (11, 1, 'private', 0, NULL)",
    ] as $sql) {
        $connection->exec($sql);
    }

    $settings = new SmokeSettings([
        'dre_seo_noindex_browse' => '1',
        'dre_seo_default_description' => 'Smoke-test description',
        'dre_seo_jsonld_enabled' => '1',
        'dre_seo_citation_meta' => '1',
        'dre_seo_sitemap_enabled' => '1',
        'dre_seo_ping_enabled' => '0',
    ]);
    $siteSettings = new SmokeSiteSettings();
    $logger = new Laminas\Log\Logger();
    $logWriter = new Laminas\Log\Writer\Mock();
    $logger->addWriter($logWriter);

    $services = new Laminas\ServiceManager\ServiceManager($config['service_manager']);
    $services->setService('Config', $config);
    $services->setService('Omeka\\Connection', $connection);
    $services->setService('Omeka\\Settings', $settings);
    $services->setService('Omeka\\Settings\\Site', $siteSettings);
    $services->setService('Omeka\\HttpClient', new Laminas\Http\Client());
    $services->setService('Omeka\\ApiManager', reflected(Omeka\Api\Manager::class));
    $services->setService('Omeka\\Job\\Dispatcher', reflected(Omeka\Job\Dispatcher::class));
    $services->setService('Omeka\\Logger', $logger);

    $serviceTypes = [
        DRESeo\Service\StructuredData::class,
        DRESeo\Service\CitationMeta::class,
        DRESeo\Service\HeadMetadata::class,
        DRESeo\Service\SitemapGenerator::class,
        DRESeo\Service\PageSeoStore::class,
        DRESeo\Service\Pinger::class,
        DRESeo\Service\PingQueue::class,
    ];
    foreach ($serviceTypes as $type) {
        smokeAssert($services->get($type) instanceof $type, sprintf('Factory failed for %s.', $type));
    }

    $sitemapController = (new DRESeo\Service\Controller\SitemapControllerFactory())(
        $services,
        DRESeo\Controller\SitemapController::class
    );
    $seoController = (new DRESeo\Service\Controller\SeoControllerFactory())(
        $services,
        DRESeo\Controller\Admin\SeoController::class
    );
    smokeAssert($sitemapController instanceof DRESeo\Controller\SitemapController, 'Sitemap controller factory failed.');
    smokeAssert($seoController instanceof DRESeo\Controller\Admin\SeoController, 'Admin controller factory failed.');

    $formConfig = $config['form_elements'];
    $formConfig['invokables'][Omeka\Form\Element\Asset::class] = Omeka\Form\Element\Asset::class;
    $forms = new Laminas\Form\FormElementManager($services, $formConfig);
    $invalidForm = $forms->build(DRESeo\Form\ConfigForm::class);
    $invalidForm->setData(['dre_seo_indexnow_key' => 'not-a-hex-key']);
    smokeAssert(!$invalidForm->isValid(), 'Config form must reject an invalid IndexNow key.');
    $validForm = $forms->build(DRESeo\Form\ConfigForm::class);
    $validForm->setData(['dre_seo_indexnow_key' => 'abcdef1234567890']);
    smokeAssert($validForm->isValid(), 'Config form must accept a valid IndexNow key.');

    $module->setServiceLocator($services);
    $sharedEvents = new Laminas\EventManager\SharedEventManager();
    $module->attachListeners($sharedEvents);
    smokeAssert(
        count($sharedEvents->getListeners(['Omeka\\Controller\\Site\\Item'], 'view.show.after')) === 1,
        'Resource show listener was not attached.'
    );
    smokeAssert(
        count($sharedEvents->getListeners(['Omeka\\Api\\Adapter\\ItemAdapter'], 'api.create.post')) === 1,
        'API content-change listener was not attached.'
    );

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['REQUEST_URI'] = '/s/amira/item?fulltext=history';
    $_SERVER['QUERY_STRING'] = 'fulltext=history';

    $view = new Laminas\View\Renderer\PhpRenderer();
    $view->doctype(Laminas\View\Helper\Doctype::HTML5);
    $view->headTitle()->append('Browse');
    $view->getHelperPluginManager()->setService(
        'currentSite',
        new SmokeCurrentSite(new SmokeSiteRepresentation())
    );

    $events = new Laminas\EventManager\EventManager(
        $sharedEvents,
        ['Omeka\\Controller\\Site\\Item']
    );
    $events->triggerEvent(new Laminas\EventManager\Event(
        'view.browse.after',
        $view,
        ['view' => $view]
    ));
    $events->triggerEvent(new Laminas\EventManager\Event(
        'view.layout',
        $view,
        ['view' => $view]
    ));

    $headMeta = (string) $view->headMeta();
    $headLink = (string) $view->headLink();
    $decodedHeadMeta = html_entity_decode($headMeta, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decodedHeadLink = html_entity_decode($headLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    smokeAssert(
        str_contains($decodedHeadMeta, 'name="robots"')
            && str_contains($decodedHeadMeta, 'content="noindex, follow"'),
        "Browse listener did not emit a robots directive through Laminas HeadMeta:\n" . $headMeta
    );
    smokeAssert(
        str_contains($decodedHeadMeta, 'property="og:site_name"') && str_contains($decodedHeadMeta, 'AMIRA Smoke'),
        'Layout listener did not emit the site Open Graph metadata.'
    );
    smokeAssert(str_contains($decodedHeadMeta, 'twitter:card'), 'Layout listener did not emit Twitter metadata.');
    smokeAssert(
        str_contains($decodedHeadLink, 'rel="canonical"')
            && str_contains($decodedHeadLink, 'https://example.test/s/amira/item?fulltext=history'),
        'Browse listener did not emit the canonical URL through Laminas HeadLink.'
    );

    $services->get(DRESeo\Service\HeadMetadata::class)->addJsonLd($view, [
        '@context' => 'https://schema.org',
        'name' => '</script>',
    ]);
    $headScript = (string) $view->headScript();
    $decodedHeadScript = html_entity_decode($headScript, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    smokeAssert(str_contains($decodedHeadScript, 'application/ld+json'), 'JSON-LD did not use HeadScript.');
    smokeAssert(!str_contains($decodedHeadScript, '//<!--'), 'HeadScript wrapped JSON-LD in a JavaScript comment.');
    smokeAssert(str_contains($decodedHeadScript, '\\u003C\\/script\\u003E'), 'JSON-LD script-breakout text was not escaped.');

    /** @var DRESeo\Service\SitemapGenerator $generator */
    $generator = $services->get(DRESeo\Service\SitemapGenerator::class);
    smokeAssert(
        $generator->counts(1) === ['items' => 2, 'itemSets' => 1, 'pages' => 1],
        'Sitemap factory did not query the real DBAL connection correctly.'
    );
    $indexXml = $generator->buildIndex('https://example.test', 1, 60);
    $itemsXml = $generator->buildItems('https://example.test/s/amira', 1, 1, 60);
    $document = new DOMDocument();
    smokeAssert($document->loadXML($indexXml), 'Sitemap index is invalid XML.');
    smokeAssert(str_contains($itemsXml, '/item/1') && str_contains($itemsXml, '/item/2'), 'Public items are missing.');
    smokeAssert(!str_contains($itemsXml, '/item/4'), 'A private item leaked into the sitemap.');

    printf(
        "PASS  Omeka S %s integration smoke (%d factories, listeners, forms, head helpers, DBAL sitemap)\n",
        Omeka\Module::VERSION,
        count($serviceTypes) + 2
    );
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("FAIL  %s\n%s\n", $e->getMessage(), $e->getTraceAsString()));
    $exitCode = 1;
} finally {
    if ($generator instanceof DRESeo\Service\SitemapGenerator) {
        $generator->clearCache();
    }
    $cacheDir = $cacheBase . '/dre-seo-cache';
    if (is_dir($cacheDir)) {
        @rmdir($cacheDir);
    }
    if (is_dir($cacheBase)) {
        @rmdir($cacheBase);
    }
}

exit($exitCode);
