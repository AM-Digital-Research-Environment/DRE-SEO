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
        // Research projects: item set 20 links to project 30 (dcterms:isPartOf),
        // item set 21 shares project 31's dre:id, item set 22 links to the
        // private project 32 and stays a page of its own.
        'ALTER TABLE resource ADD COLUMN resource_class_id INTEGER',
        'CREATE TABLE vocabulary (id INTEGER PRIMARY KEY, prefix VARCHAR(255))',
        'CREATE TABLE property (id INTEGER PRIMARY KEY, vocabulary_id INTEGER, local_name VARCHAR(255))',
        'CREATE TABLE resource_class (id INTEGER PRIMARY KEY, vocabulary_id INTEGER, local_name VARCHAR(255))',
        'CREATE TABLE value (id INTEGER PRIMARY KEY, resource_id INTEGER, property_id INTEGER, value_resource_id INTEGER, value TEXT, is_public INTEGER)',
        "INSERT INTO vocabulary VALUES (1, 'dcterms'), (2, 'foaf'), (3, 'dre')",
        "INSERT INTO property VALUES (10, 1, 'isPartOf'), (11, 3, 'id')",
        "INSERT INTO resource_class VALUES (5, 2, 'Project')",
        "INSERT INTO resource (id, resource_type, is_public, modified) VALUES (20, 'Omeka\\Entity\\ItemSet', 1, NULL), (21, 'Omeka\\Entity\\ItemSet', 1, NULL), (22, 'Omeka\\Entity\\ItemSet', 1, NULL)",
        "INSERT INTO resource (id, resource_type, is_public, modified, resource_class_id) VALUES (30, 'Omeka\\Entity\\Item', 1, NULL, 5), (31, 'Omeka\\Entity\\Item', 1, NULL, 5), (32, 'Omeka\\Entity\\Item', 0, NULL, 5)",
        'INSERT INTO site_item_set VALUES (20, 1), (21, 1), (22, 1)',
        "INSERT INTO value VALUES (1, 20, 10, 30, NULL, 1), (2, 21, 11, NULL, 'P-1', 1), (3, 31, 11, NULL, 'P-1', 1), (4, 22, 10, 32, NULL, 1)",
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
        DRESeo\Service\CitationKindMap::class,
        DRESeo\Service\CitationData::class,
        DRESeo\Service\CitationFormatter::class,
        DRESeo\Service\CitationExport::class,
        DRESeo\Service\HeadMetadata::class,
        DRESeo\Service\SitemapGenerator::class,
        DRESeo\Service\PageSeoStore::class,
        DRESeo\Service\Pinger::class,
        DRESeo\Service\PingQueue::class,
        DRESeo\Service\DefaultSite::class,
        DRESeo\Service\ResourceSummary::class,
        DRESeo\Service\Signposting::class,
        DRESeo\Service\LinkedDataIdentity::class,
        DRESeo\Service\ImageDimensions::class,
        DRESeo\Service\LandingPages::class,
        DRESeo\Service\ProjectResolver::class,
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
    $citationController = (new DRESeo\Service\Controller\CitationControllerFactory())(
        $services,
        DRESeo\Controller\CitationController::class
    );
    smokeAssert($sitemapController instanceof DRESeo\Controller\SitemapController, 'Sitemap controller factory failed.');
    smokeAssert($seoController instanceof DRESeo\Controller\Admin\SeoController, 'Admin controller factory failed.');
    smokeAssert($citationController instanceof DRESeo\Controller\CitationController, 'Citation controller factory failed.');

    // The citation stack, end to end against Omeka's real value/record classes:
    // a formatted citation and every offered download serialisation.
    $citationRecord = new DRESeo\Service\Citation\CitationRecord(
        id: 42,
        kind: DRESeo\Service\CitationKind::Article,
        title: 'Islamic Reform in Colonial Burkina Faso',
        authors: [DRESeo\Service\Citation\Creator::parse('Frédérick Madore', false)],
        issued: DRESeo\Service\Citation\IssuedDate::parse('2021-05-16'),
        container: 'Journal of African History',
        doi: '10.1017/S0021853721000372',
        url: 'https://example.test/s/amira/item/42',
    );
    $formatter = $services->get(DRESeo\Service\CitationFormatter::class);
    $citationHtml = $formatter->format($citationRecord, 'chicago');
    smokeAssert(
        str_contains($citationHtml, 'Madore, Frédérick.')
            && str_contains($citationHtml, '<em>Journal of African History</em>')
            && str_contains($citationHtml, 'https://doi.org/10.1017/S0021853721000372'),
        'Chicago citation did not render the expected segments: ' . $citationHtml
    );
    $export = $services->get(DRESeo\Service\CitationExport::class);
    foreach (array_keys(DRESeo\Service\CitationExport::FORMATS) as $format) {
        $serialised = $export->serialize($citationRecord, $format);
        smokeAssert(
            is_string($serialised) && $serialised !== '',
            sprintf('Citation export produced nothing for %s.', $format)
        );
    }

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
    foreach (['Omeka\\Api\\Adapter\\ItemSetAdapter', 'Omeka\\Api\\Adapter\\SitePageAdapter'] as $adapterClass) {
        smokeAssert(
            count($sharedEvents->getListeners([$adapterClass], 'api.delete.pre')) === 1,
            sprintf('IndexNow delete listener was not attached to %s.', $adapterClass)
        );
    }

    // Site settings: the module's fields join core's site settings form, the
    // redirect is on until switched off, and a malformed line is refused.
    $siteSettingsForm = new Omeka\Form\SiteSettingsForm();
    $formEvents = new Laminas\EventManager\EventManager($sharedEvents, [Omeka\Form\SiteSettingsForm::class]);
    $formEvents->triggerEvent(new Laminas\EventManager\Event('form.add_elements', $siteSettingsForm));
    smokeAssert(
        $siteSettingsForm->has('dre_seo_landing_pages') && $siteSettingsForm->has('dre_seo_project_redirect'),
        'The site settings fields were not added.'
    );
    smokeAssert($siteSettingsForm->get('dre_seo_project_redirect')->isChecked(), 'The project redirect must default to on.');
    smokeAssert(
        str_contains((string) $siteSettingsForm->get('dre_seo_landing_pages')->getAttribute('placeholder'), '29918 = publications'),
        'The landing pages placeholder does not show the defaults.'
    );
    $formEvents->triggerEvent(new Laminas\EventManager\Event(
        'form.add_input_filters',
        $siteSettingsForm,
        ['inputFilter' => $siteSettingsForm->getInputFilter()]
    ));
    $siteSettingsForm->setData(['dre_seo_project_redirect' => '0', 'dre_seo_landing_pages' => "20 = research-projects\nnot a line"]);
    smokeAssert(!$siteSettingsForm->isValid(), 'A malformed landing-page line was accepted.');
    $siteSettingsForm->setData(['dre_seo_project_redirect' => '0', 'dre_seo_landing_pages' => "20 = research\n29918 =\n"]);
    smokeAssert($siteSettingsForm->isValid(), 'Valid site settings were refused: ' . json_encode($siteSettingsForm->getMessages()));

    // The project item-set redirect, through a real MvcEvent: a project's
    // item-set page answers 301 with the project's page; nothing else does.
    $smokeProject = new class extends Omeka\Api\Representation\ItemRepresentation {
        public function __construct()
        {
        }

        public function id()
        {
            return 30;
        }

        public function isPublic()
        {
            return true;
        }

        public function resourceClass()
        {
            return new class {
                public function term(): string
                {
                    return 'foaf:Project';
                }
            };
        }

        public function siteUrl($siteSlug = null, $canonical = false)
        {
            return 'https://example.test/s/' . $siteSlug . '/item/30';
        }
    };
    $smokeLink = new class ($smokeProject) extends Omeka\Api\Representation\ValueRepresentation {
        public function __construct(private readonly object $target)
        {
        }

        public function valueResource()
        {
            return $this->target;
        }
    };
    $smokeSet = static fn (int $id, array $partOf): object => new class ($id, $partOf) extends Omeka\Api\Representation\ItemSetRepresentation {
        public function __construct(private readonly int $setId, private readonly array $partOf)
        {
        }

        public function id()
        {
            return $this->setId;
        }

        public function value($term, array $options = [])
        {
            $values = $term === 'dcterms:isPartOf' ? $this->partOf : [];
            return empty($options['all']) ? ($values[0] ?? null) : $values;
        }
    };
    $smokeApi = new class ([20 => $smokeSet(20, [$smokeLink]), 3 => $smokeSet(3, [])]) extends Omeka\Api\Manager {
        public function __construct(private readonly array $itemSets)
        {
        }

        public function read($resource, $id, array $data = [], array $options = [])
        {
            if ($resource !== 'item_sets' || !isset($this->itemSets[$id])) {
                throw new Omeka\Api\Exception\NotFoundException('Not found');
            }
            return new Omeka\Api\Response($this->itemSets[$id]);
        }
    };
    $redirectServices = new Laminas\ServiceManager\ServiceManager($config['service_manager']);
    $redirectServices->setService('Config', $config);
    $redirectServices->setService('Omeka\\ApiManager', $smokeApi);
    $redirectServices->setService('Omeka\\Settings\\Site', $siteSettings);
    $redirect = static function (string $method, string $route, int $itemSetId) use ($module, $redirectServices): ?Laminas\Http\Response {
        $request = new Laminas\Http\Request();
        $request->setMethod($method);
        $response = new Laminas\Http\Response();
        $routeMatch = new Laminas\Router\Http\RouteMatch(['item-set-id' => (string) $itemSetId]);
        $routeMatch->setMatchedRouteName($route);
        $event = new Laminas\Mvc\MvcEvent();
        $event->setApplication(new Laminas\Mvc\Application($redirectServices, new Laminas\EventManager\EventManager(), $request, $response));
        $event->setRequest($request);
        $event->setResponse($response);
        $event->setRouteMatch($routeMatch);
        $event->setViewModel(new Laminas\View\Model\ViewModel(['site' => new SmokeSiteRepresentation()]));
        return $module->redirectProjectItemSet($event);
    };
    $moved = $redirect('GET', 'site/item-set', 20);
    smokeAssert(
        $moved !== null && $moved->getStatusCode() === 301
            && $moved->getHeaders()->get('Location')->getFieldValue() === 'https://example.test/s/amira/item/30',
        'A project item-set page did not redirect to the project.'
    );
    smokeAssert($redirect('HEAD', 'site/item-set', 20)?->getStatusCode() === 301, 'A HEAD request was not redirected.');
    smokeAssert($redirect('POST', 'site/item-set', 20) === null, 'A POST was redirected.');
    smokeAssert($redirect('GET', 'site/resource', 20) === null, 'Another route was redirected.');
    smokeAssert($redirect('GET', 'site/item-set', 3) === null, 'An item set with no project was redirected.');
    smokeAssert($redirect('GET', 'site/item-set', 404) === null, 'An unreadable item set was redirected.');
    $siteSettings->set('dre_seo_project_redirect', '0', 1);
    smokeAssert($redirect('GET', 'site/item-set', 20) === null, 'The redirect ignored the site setting.');
    $siteSettings->delete('dre_seo_project_redirect', 1);

    // IndexNow: the api.*.post events carry the entity, not its
    // representation; a public record, one just made private and one about to
    // be deleted are queued at their page URL on the default site.
    $defaultSite = $services->get(DRESeo\Service\DefaultSite::class);
    foreach (['site' => new SmokeSiteRepresentation(), 'resolved' => true] as $property => $value) {
        $reflection = new ReflectionProperty(DRESeo\Service\DefaultSite::class, $property);
        $reflection->setValue($defaultSite, $value);
    }
    $settings->set('dre_seo_ping_enabled', '1');
    $settings->set('dre_seo_indexnow_key', 'abcdef1234567890');
    $entity = static fn (int $id, bool $public): object => new class ($id, $public) {
        public function __construct(private int $id, public bool $public)
        {
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function isPublic(): bool
        {
            return $this->public;
        }
    };
    $records = [7 => $entity(7, true), 8 => $entity(8, false), 9 => $entity(9, false), 10 => $entity(10, true)];
    $adapter = new class ($records) {
        public function __construct(private array $records)
        {
        }

        public function getRepresentation(object $entity): object
        {
            return new class ($entity->getId()) {
                public function __construct(private int $id)
                {
                }

                public function siteUrl($slug = null, $canonical = false): string
                {
                    return 'https://example.test/s/' . $slug . '/item/' . $this->id;
                }
            };
        }

        public function findEntity($id, $request = null): object
        {
            return $this->records[$id];
        }
    };
    $apiEvents = new Laminas\EventManager\EventManager($sharedEvents, ['Omeka\\Api\\Adapter\\ItemAdapter']);
    $update = new Omeka\Api\Request(Omeka\Api\Request::UPDATE, 'items');
    // 7: public, edited. 8: public until this update made it private.
    // 9: private before and after. 10: public, deleted.
    $records[8]->public = true;
    $apiEvents->triggerEvent(new Laminas\EventManager\Event('api.hydrate.pre', $adapter, ['entity' => $records[8], 'request' => $update]));
    $records[8]->public = false;
    $apiEvents->triggerEvent(new Laminas\EventManager\Event('api.hydrate.pre', $adapter, ['entity' => $records[9], 'request' => $update]));
    foreach ([7, 8, 9] as $id) {
        $apiEvents->triggerEvent(new Laminas\EventManager\Event('api.update.post', $adapter, [
            'request' => $update,
            'response' => new Omeka\Api\Response($records[$id]),
        ]));
    }
    $delete = new Omeka\Api\Request(Omeka\Api\Request::DELETE, 'items');
    $delete->setId(10);
    $apiEvents->triggerEvent(new Laminas\EventManager\Event('api.delete.pre', $adapter, ['request' => $delete]));
    smokeAssert(
        $settings->get('dre_seo_ping_pending') === [
            'https://example.test/s/amira/item/7',
            'https://example.test/s/amira/item/8',
            'https://example.test/s/amira/item/10',
        ],
        'IndexNow did not queue the changed records: ' . json_encode($settings->get('dre_seo_ping_pending'))
    );
    $settings->set('dre_seo_ping_enabled', '0');

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
    // JSON_HEX_TAG escapes the angle brackets, so the value cannot close the
    // script element; the slash may stay literal (URLs read cleanly).
    smokeAssert(str_contains($decodedHeadScript, '\\u003C/script\\u003E'), 'JSON-LD script-breakout text was not escaped.');
    smokeAssert(substr_count($decodedHeadScript, '</script>') === 1, 'JSON-LD content closed its own script element.');

    /** @var DRESeo\Service\SitemapGenerator $generator */
    $generator = $services->get(DRESeo\Service\SitemapGenerator::class);
    smokeAssert(
        $generator->counts(1) === ['items' => 2, 'itemSets' => 4, 'pages' => 1],
        'Sitemap factory did not query the real DBAL connection correctly.'
    );
    // The project item sets redirect, so the sitemap leaves them out, in SQL.
    $itemSetsXml = $generator->buildItemSets('https://example.test/s/amira', 1, 0, true);
    smokeAssert(
        str_contains($itemSetsXml, '/item-set/3<') && str_contains($itemSetsXml, '/item-set/22<')
            && !str_contains($itemSetsXml, '/item-set/20<') && !str_contains($itemSetsXml, '/item-set/21<'),
        "The item-set sitemap did not leave out the project item sets:\n" . $itemSetsXml
    );
    smokeAssert($generator->counts(1, true)['itemSets'] === 2, 'The project item sets were counted.');
    $generator->buildIndex('https://example.test', 1, 0, true);
    smokeAssert(
        array_filter($logWriter->events, static fn (array $e): bool => str_contains((string) $e['message'], 'sitemap')) === [],
        'A sitemap query failed: ' . json_encode(array_column($logWriter->events, 'message'))
    );
    $indexXml = $generator->buildIndex('https://example.test', 1, 60);
    $itemsXml = $generator->buildItems('https://example.test/s/amira', 1, 1, 60);
    $document = new DOMDocument();
    smokeAssert($document->loadXML($indexXml), 'Sitemap index is invalid XML.');
    smokeAssert(str_contains($itemsXml, '/item/1') && str_contains($itemsXml, '/item/2'), 'Public items are missing.');
    smokeAssert(!str_contains($itemsXml, '/item/4'), 'A private item leaked into the sitemap.');

    // The view helper the theme calls: it must resolve through the module's own
    // view_helpers config, or the rail silently falls back to its baseline.
    $viewHelpers = new Laminas\View\HelperPluginManager($services, $config['view_helpers']);
    $citationHelper = $viewHelpers->get('dreCitation');
    smokeAssert(
        $citationHelper instanceof DRESeo\View\Helper\Citation,
        'The dreCitation view helper did not resolve through the module config.'
    );
    smokeAssert(
        $viewHelpers->get('dreBreadcrumb') instanceof DRESeo\View\Helper\Breadcrumb,
        'The dreBreadcrumb view helper did not resolve through the module config.'
    );

    // DRE Linked Data is optional: without its helper there is no identity;
    // with it, the helper is reached through the view helper manager.
    $item = reflected(Omeka\Api\Representation\ItemRepresentation::class);
    smokeAssert(
        $services->get(DRESeo\Service\LinkedDataIdentity::class)->uri($item) === null,
        'A resource URI was claimed without DRE Linked Data.'
    );
    $withLinkedData = new Laminas\ServiceManager\ServiceManager();
    $withLinkedData->setService('ViewHelperManager', new Laminas\View\HelperPluginManager($withLinkedData, [
        'factories' => [
            'dreLinkedData' => static fn () => static fn ($resource): array => [
                'uri' => 'https://example.test/resource/7',
                'downloads' => [['label' => 'Turtle', 'type' => 'text/turtle', 'url' => 'https://example.test/resource/7.ttl']],
            ],
        ],
    ]));
    $identity = (new DRESeo\Service\LinkedDataIdentityFactory())($withLinkedData, DRESeo\Service\LinkedDataIdentity::class);
    smokeAssert(
        $identity->uri($item) === 'https://example.test/resource/7' && count($identity->documents($item)) === 1,
        'The dreLinkedData helper was not reached through the view helper manager.'
    );

    // The Signposting Link header must pass Laminas' header validation and be
    // sent as a multi-header, so another module's Link header survives.
    $linkHeader = new Laminas\Http\Header\GenericMultiHeader('Link', DRESeo\Service\Signposting::header([
        ['rel' => 'cite-as', 'href' => 'https://example.test/s/amira/item/7'],
        ['rel' => 'item', 'href' => 'https://example.test/files/original/Ü 1.pdf', 'type' => 'application/pdf'],
    ]));
    $response = new Laminas\Http\Response();
    $response->getHeaders()->addHeader($linkHeader);
    $sentHeaders = $response->getHeaders()->toString();
    smokeAssert(
        str_contains($sentHeaders, 'Link: <https://example.test/s/amira/item/7>; rel="cite-as", '
            . '<https://example.test/files/original/%C3%9C%201.pdf>; rel="item"; type="application/pdf"'),
        "The Signposting Link header did not serialise:\n" . $sentHeaders
    );
    smokeAssert(
        $linkHeader instanceof Laminas\Http\Header\MultipleHeaderInterface,
        'The Link header would replace other Link headers.'
    );

    printf(
        "PASS  Omeka S %s integration smoke (%d factories, listeners, forms, site settings, project redirect, head helpers, citations, DBAL sitemap, Linked Data identity, Link header)\n",
        Omeka\Module::VERSION,
        count($serviceTypes) + 3
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
