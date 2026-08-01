<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;
use DRESeo\Service\SitemapGenerator;

require_once __DIR__ . '/../src/Service/SitemapGenerator.php';

final class FakeConnection extends Connection
{
    /** @var array<int,int> */
    public array $itemCounts = [];

    /** @var array<int,array<int,array{id:int,modified:?string}>> */
    public array $items = [];

    /** @var array<int,array<int,array{id:int,modified:?string}>> */
    public array $itemSets = [];

    /** @var array<int,array<int,array{id:int,slug:string,modified:?string}>> */
    public array $pages = [];

    /** @var string[] */
    public array $queries = [];

    public function fetchOne(string $query, array $params = []): mixed
    {
        $this->queries[] = $query;
        return $this->itemCounts[(int) ($params['s'] ?? 0)] ?? 0;
    }

    public function fetchAllAssociative(string $query, array $params = []): array
    {
        $this->queries[] = $query;
        $siteId = (int) ($params['s'] ?? 0);
        if (str_contains($query, 'JOIN item_site')) {
            preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $matches);
            return array_slice(
                $this->items[$siteId] ?? [],
                (int) ($matches[2] ?? 0),
                (int) ($matches[1] ?? 0)
            );
        }
        if (str_contains($query, 'JOIN site_item_set')) {
            return $this->itemSets[$siteId] ?? [];
        }
        if (str_contains($query, 'FROM site_page')) {
            return $this->pages[$siteId] ?? [];
        }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}

/** @return array<string,mixed> */
function sitemapConfig(int $chunkSize = 2): array
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

test('sitemap index chunks items and isolates cached sites and hosts', function (): void {
    $connection = new FakeConnection();
    $connection->itemCounts = [1 => 1, 2 => 5];
    $cacheDir = sys_get_temp_dir() . '/dre-seo-test-' . bin2hex(random_bytes(6));
    $generator = new SitemapGenerator($connection, sitemapConfig(), $cacheDir, new MemoryLogger());

    try {
        $siteOne = $generator->buildIndex('https://one.example', 1, 3600);
        $siteTwo = $generator->buildIndex('https://two.example', 2, 3600);
        $movedHost = $generator->buildIndex('https://moved.example', 1, 3600);

        assertSameValue([
            'https://one.example/sitemap-pages.xml',
            'https://one.example/sitemap-item-sets.xml',
            'https://one.example/sitemap-items-1.xml',
        ], xmlLocations($siteOne, 'sitemap'));
        assertSameValue(5, count(xmlLocations($siteTwo, 'sitemap')));
        assertSameValue('https://moved.example/sitemap-pages.xml', xmlLocations($movedHost, 'sitemap')[0]);
    } finally {
        $generator->clearCache();
        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }
    }
});

test('pages sitemap follows navigation order and emits the bare-domain homepage', function (): void {
    $connection = new FakeConnection();
    $connection->pages[1] = [
        ['id' => 1, 'slug' => 'home', 'modified' => '2026-01-02 03:04:05'],
        ['id' => 2, 'slug' => 'research & data', 'modified' => null],
        ['id' => 3, 'slug' => 'team', 'modified' => null],
        ['id' => 4, 'slug' => 'hidden', 'modified' => null],
    ];
    $generator = new SitemapGenerator($connection, sitemapConfig(), null, new MemoryLogger());
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
        'https://example.test',
        1,
        0,
        $navigation,
        1
    );

    assertSameValue([
        'https://example.test/',
        'https://example.test/s/amira/page/research%20%26%20data',
        'https://example.test/s/amira/page/team',
        'https://example.test/s/amira/page/hidden',
    ], xmlLocations($xml, 'url'));
    assertTrue(str_contains($xml, '<priority>0.8</priority>'));
    assertTrue(str_contains($xml, '<priority>0.5</priority>'));
    assertTrue(str_contains($xml, '<priority>0.4</priority>'));
    assertTrue(str_contains($xml, '<lastmod>2026-01-02T03:04:05+00:00</lastmod>'));
});

test('item sitemap uses the requested chunk offset and valid XML escaping', function (): void {
    $connection = new FakeConnection();
    $connection->items[1] = [
        ['id' => 1, 'modified' => null],
        ['id' => 2, 'modified' => null],
        ['id' => 3, 'modified' => '2026-06-07 08:09:10'],
        ['id' => 4, 'modified' => null],
    ];
    $generator = new SitemapGenerator($connection, sitemapConfig(), null, new MemoryLogger());

    $xml = $generator->buildItems('https://example.test/s/amira', 1, 2, 0);

    assertSameValue([
        'https://example.test/s/amira/item/3',
        'https://example.test/s/amira/item/4',
    ], xmlLocations($xml, 'url'));
    assertTrue((bool) array_filter(
        $connection->queries,
        static fn (string $query): bool => str_contains($query, 'LIMIT 2 OFFSET 2')
    ));
});

test('sitemap database failures return safe empty data and are logged', function (): void {
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
    $generator = new SitemapGenerator($connection, sitemapConfig(), null, $logger);

    assertSameValue(['items' => 0, 'itemSets' => 0, 'pages' => 0], $generator->counts(42));
    assertSameValue(3, count($logger->records['err'] ?? []));
    assertTrue(str_contains($logger->records['err'][0]['message'], 'sitemap generation'));
    assertSameValue(42, $logger->records['err'][0]['extra']['site_id']);
});
