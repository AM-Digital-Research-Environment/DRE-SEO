<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use Doctrine\DBAL\Connection;
use DOMDocument;
use DOMXPath;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\Sitemap\SitemapRepository;
use DRESeo\Service\Sitemap\UrlsetWriter;
use DRESeo\Service\Sitemap\XmlCache;
use DRESeo\Service\SitemapGenerator;
use DRESeo\Test\Double\FakeConnection;
use DRESeo\Test\Double\InMemorySitemapRepository;
use DRESeo\Test\Double\MemoryLogger;
use Laminas\Log\LoggerInterface;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The sitemap index and its children, over an in-memory connection
 * (tests/Double/FakeConnection) that answers the generator's queries.
 */
final class SitemapGeneratorTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function sitemapConfig(int $chunkSize = 2): array
    {
        return [
            'item_chunk_size' => $chunkSize,
            'priority' => [
                'home' => '1.0',
                'section' => '0.8',
                'item' => '0.6',
                'page' => '0.5',
                'browse' => '0.4',
            ],
            'changefreq' => [
                'home' => 'daily',
                'item' => 'monthly',
                'page' => 'monthly',
                'browse' => 'weekly',
            ],
        ];
    }

    /**
     * The generator as its factory assembles it, over $connection.
     *
     * @param array<string,mixed> $config
     */
    private static function generator(Connection $connection, array $config, ?string $cacheDir, LoggerInterface $logger): SitemapGenerator
    {
        return new SitemapGenerator(
            new SitemapRepository($connection, $logger),
            new UrlsetWriter(),
            new XmlCache($cacheDir, $logger),
            $config
        );
    }

    /** @return string[] the <loc> of every $element in a sitemap document */
    private static function xmlLocations(string $xml, string $element): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml), 'Generated sitemap must be valid XML');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $nodes = $xpath->query('//s:' . $element . '/s:loc');
        self::assertTrue($nodes !== false, 'Sitemap XPath query failed');
        $locations = [];
        foreach ($nodes as $node) {
            $locations[] = $node->textContent;
        }
        return $locations;
    }

    public function testSitemapIndexChunksItemsAndIsolatesCachedSitesAndHosts(): void
    {
        $connection = new FakeConnection();
        $connection->itemCounts = [1 => 1, 2 => 5];
        $cacheDir = sys_get_temp_dir() . '/dre-seo-test-' . bin2hex(random_bytes(6));
        $generator = self::generator($connection, self::sitemapConfig(), $cacheDir, new MemoryLogger());

        try {
            $siteOne = $generator->buildIndex('https://one.example', 1, 3600)->xml;
            $siteTwo = $generator->buildIndex('https://two.example', 2, 3600)->xml;
            $movedHost = $generator->buildIndex('https://moved.example', 1, 3600)->xml;

            $this->assertSame([
                'https://one.example/sitemap-pages.xml',
                'https://one.example/sitemap-item-sets.xml',
                'https://one.example/sitemap-items-1.xml',
            ], self::xmlLocations($siteOne, 'sitemap'));
            $this->assertSame(5, count(self::xmlLocations($siteTwo, 'sitemap')));
            $this->assertSame('https://moved.example/sitemap-pages.xml', self::xmlLocations($movedHost, 'sitemap')[0]);
        } finally {
            $generator->destroyCache();
        }
    }

    public function testDestroyingTheCacheRemovesItsDirectoryWithEverythingInIt(): void
    {
        $connection = new FakeConnection();
        $connection->itemCounts = [1 => 1];
        $cacheDir = sys_get_temp_dir() . '/dre-seo-test-' . bin2hex(random_bytes(6));
        $logger = new MemoryLogger();
        $generator = self::generator($connection, self::sitemapConfig(), $cacheDir, $logger);

        $generator->buildIndex('https://one.example', 1, 3600);
        $this->assertNotSame([], glob($cacheDir . '/*.xml'), 'the index was cached');
        file_put_contents($cacheDir . '/left-over.tmp', 'x');

        $generator->destroyCache();

        $this->assertDirectoryDoesNotExist($cacheDir);
        $this->assertArrayNotHasKey('warn', $logger->records, 'a removed directory is not a failure');
    }

    public function testPagesSitemapFollowsNavigationOrderAndListsTheHomePageAtItsOwnURL(): void
    {
        $connection = new FakeConnection();
        $connection->pages[1] = [
            ['id' => 1, 'slug' => 'home', 'modified' => '2026-01-02 03:04:05'],
            ['id' => 2, 'slug' => 'research & data', 'modified' => null],
            ['id' => 3, 'slug' => 'team', 'modified' => null],
            ['id' => 4, 'slug' => 'hidden', 'modified' => null],
        ];
        $generator = self::generator($connection, self::sitemapConfig(), null, new MemoryLogger());
        $navigation = [[
            'type' => 'page',
            'data' => ['id' => 2],
            'links' => [[
                'type' => 'page',
                'data' => ['id' => 3],
            ]],
        ]];

        $xml = $generator->buildPages(
            'https://example.test/s/amira',
            1,
            0,
            $navigation,
            1
        )->xml;

        // `/` and `/s/amira` only redirect to the home page; it is listed at the
        // URL its canonical names.
        $this->assertSame([
            'https://example.test/s/amira/page/home',
            'https://example.test/s/amira/page/research%20%26%20data',
            'https://example.test/s/amira/page/team',
            'https://example.test/s/amira/page/hidden',
        ], self::xmlLocations($xml, 'url'));
        $this->assertTrue(str_contains($xml, '<priority>0.8</priority>'));
        $this->assertTrue(str_contains($xml, '<priority>0.5</priority>'));
        $this->assertTrue(str_contains($xml, '<priority>0.4</priority>'));
        $this->assertTrue(str_contains($xml, '<lastmod>2026-01-02T03:04:05+00:00</lastmod>'));
    }

    public function testItemSitemapUsesTheRequestedChunkOffsetAndValidXMLEscaping(): void
    {
        $connection = new FakeConnection();
        $connection->items[1] = [
            ['id' => 1, 'modified' => null],
            ['id' => 2, 'modified' => null],
            ['id' => 3, 'modified' => '2026-06-07 08:09:10'],
            ['id' => 4, 'modified' => null],
        ];
        $generator = self::generator($connection, self::sitemapConfig(), null, new MemoryLogger());

        $xml = $generator->buildItems('https://example.test/s/amira', 1, 2, 0)->xml;

        $this->assertSame([
            'https://example.test/s/amira/item/3',
            'https://example.test/s/amira/item/4',
        ], self::xmlLocations($xml, 'url'));
        $this->assertTrue((bool) array_filter(
            $connection->queries,
            static fn (string $query): bool => str_contains($query, 'LIMIT 2 OFFSET 2')
        ));
    }

    public function testSitemapDatabaseFailuresReturnSafeEmptyDataAndAreLogged(): void
    {
        $connection = new class extends Connection {
            public function fetchOne(string $query, array $params = []): mixed
            {
                throw new RuntimeException('Database unavailable.');
            }

            public function fetchAllAssociative(string $query, array $params = []): array
            {
                throw new RuntimeException('Database unavailable.');
            }
        };
        $logger = new MemoryLogger();
        $generator = self::generator($connection, self::sitemapConfig(), null, $logger);

        $this->assertSame(['items' => 0, 'itemSets' => 0, 'pages' => 0], $generator->counts(42));
        $this->assertSame(3, count($logger->records['err'] ?? []));
        $this->assertTrue(str_contains($logger->records['err'][0]['message'], 'sitemap generation'));
        $this->assertSame(42, $logger->records['err'][0]['extra']['site_id']);
    }

    public function testSitemapIndexDatesEachChildByItsLatestChangeNeverByTheRebuild(): void
    {
        $connection = new FakeConnection();
        $connection->itemCounts = [1 => 1];
        $connection->latest = ['pages' => '2026-06-25 08:52:27', 'item-sets' => null, 'items' => '2026-10-07 12:00:00'];
        $generator = self::generator($connection, self::sitemapConfig(), null, new MemoryLogger());

        $xml = $generator->buildIndex('https://example.test', 1, 0)->xml;

        $this->assertTrue(str_contains($xml, 'sitemap-pages.xml</loc><lastmod>2026-06-25T08:52:27+00:00</lastmod>'), 'pages dated by their latest edit');
        $this->assertTrue(str_contains($xml, 'sitemap-items-1.xml</loc><lastmod>2026-10-07T12:00:00+00:00</lastmod>'), 'items dated by their latest edit');
        $this->assertTrue(str_contains($xml, 'sitemap-item-sets.xml</loc></sitemap>'), 'no change on record means no lastmod');
    }

    public function testTheShippedSitemapConfigEmitsNoPriorityOrChangefreqHints(): void
    {
        // Google and Bing both document that they ignore them.
        $config = require __DIR__ . '/../../config/module.config.php';
        $connection = new FakeConnection();
        $connection->items[1] = [['id' => 5, 'modified' => '2026-01-01 00:00:00']];
        $generator = self::generator($connection, $config['dre_seo']['sitemap'], null, new MemoryLogger());

        $xml = $generator->buildItems('https://example.test/s/amira', 1, 1, 0)->xml;

        $this->assertTrue(!str_contains($xml, '<priority>') && !str_contains($xml, '<changefreq>'));
        $this->assertTrue(str_contains($xml, '<lastmod>2026-01-01T00:00:00+00:00</lastmod>'));
    }

    public function testPagesAnEditorSetToNoindexAreNotListed(): void
    {
        $connection = new FakeConnection();
        $connection->pages[1] = [
            ['id' => 1, 'slug' => 'home', 'modified' => null],
            ['id' => 2, 'slug' => 'imprint', 'modified' => null],
            ['id' => 3, 'slug' => 'team', 'modified' => null],
        ];
        $generator = self::generator($connection, self::sitemapConfig(), null, new MemoryLogger());

        $xml = $generator->buildPages('https://example.test/s/amira', 1, 0, [], 1, [2])->xml;

        $this->assertSame([
            'https://example.test/s/amira/page/home',
            'https://example.test/s/amira/page/team',
        ], self::xmlLocations($xml, 'url'));
    }

    public function testPageOverridesNameTheNoindexPagesTheSitemapLeavesOut(): void
    {
        $store = new PageSeoStore(new SiteSettings());
        $store->setSite(1);
        $store->replaceAll([2 => ['robots' => 'noindex, follow'], 3 => ['robots' => 'index, follow'], 4 => ['title' => 'x']]);

        $this->assertSame([2], $store->noindexPageIds());
    }

    public function testItemSetsWhosePageRedirectsToTheirProjectLeaveTheSitemap(): void
    {
        $connection = new FakeConnection();
        $connection->itemSets[1] = [
            ['id' => 20, 'modified' => null],
            ['id' => 6259, 'modified' => null],
            ['id' => 29918, 'modified' => null],
        ];
        $connection->projectSetIds = [6259];
        $generator = self::generator($connection, self::sitemapConfig(), null, new MemoryLogger());

        $this->assertSame([
            'https://example.test/s/amira/item-set/20',
            'https://example.test/s/amira/item-set/29918',
        ], self::xmlLocations($generator->buildItemSets('https://example.test/s/amira', 1, 0, true)->xml, 'url'));
        $this->assertSame(2, $generator->counts(1, true)['itemSets']);

        // The site keeps its item-set pages: every public item set is listed.
        $this->assertSame(3, count(self::xmlLocations($generator->buildItemSets('https://example.test/s/amira', 1, 0)->xml, 'url')));
        $this->assertSame(3, $generator->counts(1)['itemSets']);

        // One query per sitemap, the exclusion inside it.
        $exclusions = array_filter($connection->queries, static fn (string $q): bool => str_contains($q, 'NOT EXISTS'));
        $this->assertSame(2, count($exclusions));
        foreach ($exclusions as $query) {
            $this->assertTrue(str_contains($query, "c.local_name = 'Project'") && str_contains($query, "sp.local_name = 'id'"));
        }
    }

    // ─── The policy alone, over an in-memory repository ─────────────────────

    /** @param array<string,mixed> $config */
    private static function policy(InMemorySitemapRepository $repository, array $config = []): SitemapGenerator
    {
        return new SitemapGenerator($repository, new UrlsetWriter(), new XmlCache(null, new MemoryLogger()), $config);
    }

    public function testTheIndexListsOneChildPerItemChunkDatedByItsOwnRows(): void
    {
        $repository = new InMemorySitemapRepository();
        $repository->items = [
            ['id' => 1, 'modified' => '2026-01-01 00:00:00'],
            ['id' => 2, 'modified' => '2026-03-01 00:00:00'],
            ['id' => 3, 'modified' => null],
            ['id' => 4, 'modified' => '2026-02-01 00:00:00'],
            ['id' => 5, 'modified' => null],
        ];

        $xml = self::policy($repository, ['item_chunk_size' => 2])->buildIndex('https://example.test', 7, 0)->xml;

        $this->assertSame([
            'https://example.test/sitemap-pages.xml',
            'https://example.test/sitemap-item-sets.xml',
            'https://example.test/sitemap-items-1.xml',
            'https://example.test/sitemap-items-2.xml',
            'https://example.test/sitemap-items-3.xml',
        ], self::xmlLocations($xml, 'sitemap'));
        $this->assertStringContainsString('sitemap-items-1.xml</loc><lastmod>2026-03-01T00:00:00+00:00</lastmod>', $xml);
        $this->assertStringContainsString('sitemap-items-2.xml</loc><lastmod>2026-02-01T00:00:00+00:00</lastmod>', $xml);
        $this->assertStringContainsString('sitemap-items-3.xml</loc></sitemap>', $xml, 'nothing dated, no lastmod');
    }

    public function testAnItemsChunkReadsItsOwnSliceOfTheRepository(): void
    {
        $repository = new InMemorySitemapRepository();
        $repository->items = [['id' => 1, 'modified' => null], ['id' => 2, 'modified' => null], ['id' => 3, 'modified' => null]];

        $xml = self::policy($repository, ['item_chunk_size' => 2])->buildItems('https://example.test/s/amira', 7, 2, 0)->xml;

        $this->assertSame(['siteId' => 7, 'offset' => 2, 'limit' => 2], $repository->lastItemFetch);
        $this->assertSame(['https://example.test/s/amira/item/3'], self::xmlLocations($xml, 'url'));
    }

    /** An out-of-range chunk size falls back to the protocol's 50,000. */
    public function testTheChunkSizeStaysWithinTheProtocol(): void
    {
        $repository = new InMemorySitemapRepository();
        $repository->itemCount = 120000;

        $this->assertSame(3, self::policy($repository, ['item_chunk_size' => 0])->itemChunkCount(1));
        $this->assertSame(3, self::policy($repository, ['item_chunk_size' => 60000])->itemChunkCount(1));
        $this->assertSame(12, self::policy($repository, ['item_chunk_size' => 10000])->itemChunkCount(1));
        $repository->itemCount = 0;
        $this->assertSame(1, self::policy($repository)->itemChunkCount(1), 'chunk 1 always exists');
    }

    /**
     * A database outage is not an empty collection: the build fails, so
     * the cache serves its last copy or the controller answers 503, instead
     * of a sitemap listing nothing being served and cached for a day.
     */
    public function testARepositoryFailureFailsTheBuildRatherThanEmptyingTheSitemap(): void
    {
        $repository = new InMemorySitemapRepository();
        $repository->failure = new RuntimeException('Database unavailable.');
        $generator = self::policy($repository);

        foreach (
            [
                static fn () => $generator->buildIndex('https://example.test', 1, 0),
                static fn () => $generator->buildPages('https://example.test/s/amira', 1, 0),
                static fn () => $generator->buildItemSets('https://example.test/s/amira', 1, 0),
                static fn () => $generator->buildItems('https://example.test/s/amira', 1, 1, 0),
            ] as $build
        ) {
            try {
                $build();
                $this->fail('An empty sitemap was built from a failed query.');
            } catch (RuntimeException $e) {
                $this->assertSame('Database unavailable.', $e->getMessage());
            }
        }
        // The dashboard still renders.
        $this->assertSame(['items' => 0, 'itemSets' => 0, 'pages' => 0], $generator->counts(1));
    }
}
