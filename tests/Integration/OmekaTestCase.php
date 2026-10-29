<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESeo\Module;
use DRESeo\Service\SitemapGenerator;
use DRESeo\Test\Integration\Double\InMemorySettings;
use DRESeo\Test\Integration\Double\InMemorySiteSettings;
use Laminas\EventManager\SharedEventManager;
use Laminas\Http\Client;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Manager;
use Omeka\Job\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The module as Omeka would assemble it, minus the application: its merged
 * config, a real Laminas service manager over the module's factories, an
 * in-memory SQLite database holding the tables the sitemap queries, and the
 * module's listeners attached to a real shared event manager.
 *
 * Every test gets a fresh copy.
 */
abstract class OmekaTestCase extends TestCase
{
    /**
     * Three items (one private), an item set, a public and a private page,
     * plus three research projects. Item set 20 links to project 30
     * (dcterms:isPartOf), item set 21 shares project 31's dre:id, and item set
     * 22 links to the private project 32 and stays a page of its own.
     */
    private const FIXTURE_SQL = [
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
    ];

    /** @var array<string,mixed> the module's config, its file store in a temporary directory */
    protected array $config;
    protected Module $module;
    protected Connection $connection;
    protected InMemorySettings $settings;
    protected InMemorySiteSettings $siteSettings;
    protected Mock $logWriter;
    protected ServiceManager $services;
    protected SharedEventManager $sharedEvents;
    private string $cacheBase;

    protected function setUp(): void
    {
        $this->cacheBase = sys_get_temp_dir() . '/dre-seo-omeka-integration-' . bin2hex(random_bytes(6));

        $this->module = new Module();
        $config = $this->module->getConfig();
        $config['file_store']['local']['base_path'] = $this->cacheBase;
        $this->config = $config;

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        foreach (self::FIXTURE_SQL as $sql) {
            $this->connection->exec($sql);
        }

        $this->settings = new InMemorySettings([
            'dre_seo_noindex_browse' => '1',
            'dre_seo_default_description' => 'Smoke-test description',
            'dre_seo_jsonld_enabled' => '1',
            'dre_seo_citation_meta' => '1',
            'dre_seo_sitemap_enabled' => '1',
            'dre_seo_ping_enabled' => '0',
        ]);
        $this->siteSettings = new InMemorySiteSettings();
        $logger = new Logger();
        $this->logWriter = new Mock();
        $logger->addWriter($this->logWriter);

        $this->services = new ServiceManager($config['service_manager']);
        $this->services->setService('Config', $config);
        $this->services->setService('Omeka\\Connection', $this->connection);
        $this->services->setService('Omeka\\Settings', $this->settings);
        $this->services->setService('Omeka\\Settings\\Site', $this->siteSettings);
        $this->services->setService('Omeka\\HttpClient', new Client());
        $this->services->setService('Omeka\\ApiManager', self::withoutConstructor(Manager::class));
        $this->services->setService('Omeka\\Job\\Dispatcher', self::withoutConstructor(Dispatcher::class));
        $this->services->setService('Omeka\\Logger', $logger);

        $this->module->setServiceLocator($this->services);
        $this->sharedEvents = new SharedEventManager();
        $this->module->attachListeners($this->sharedEvents);
    }

    protected function tearDown(): void
    {
        // The sitemap cache lives under the temporary file store set up above,
        // with its locks and generation stamp; removing a cache nothing wrote
        // to is a no-op.
        if (isset($this->services)) {
            $this->services->get(SitemapGenerator::class)->destroyCache();
        }
        if (is_dir($this->cacheBase)) {
            @rmdir($this->cacheBase);
        }
    }

    /**
     * An instance of a class whose constructor wants the whole application,
     * for a service the code under test only needs to hold.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
