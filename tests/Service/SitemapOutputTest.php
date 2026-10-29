<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\Sitemap\SitemapRepository;
use DRESeo\Service\Sitemap\UrlsetWriter;
use DRESeo\Service\Sitemap\XmlCache;
use DRESeo\Service\SitemapGenerator;
use DRESeo\Test\Double\FakeConnection;
use DRESeo\Test\Double\MemoryLogger;
use PHPUnit\Framework\TestCase;

/**
 * The sitemap documents byte for byte, over an in-memory connection
 * (tests/Double/FakeConnection): what crawlers have been served, pinned so a
 * refactoring of the generator cannot change a byte of it unnoticed. Each
 * document is built live and then through the cache, which must serve the
 * same bytes.
 */
final class SitemapOutputTest extends TestCase
{
    private FakeConnection $connection;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->connection = new FakeConnection();
        $this->connection->itemCounts = [1 => 3];
        $this->connection->latest = ['pages' => '2026-06-25 08:52:27', 'item-sets' => null, 'items' => '2026-10-07 12:00:00'];
        $this->connection->pages[1] = [
            ['id' => 1, 'slug' => 'home', 'modified' => '2026-01-02 03:04:05'],
            ['id' => 2, 'slug' => 'research & data', 'modified' => null],
            ['id' => 3, 'slug' => 'team', 'modified' => '2026-03-04 05:06:07'],
            ['id' => 4, 'slug' => 'exposition spéciale', 'modified' => null],
            ['id' => 5, 'slug' => 'imprint', 'modified' => null],
        ];
        $this->connection->itemSets[1] = [
            ['id' => 20, 'modified' => '2026-05-06 07:08:09'],
            ['id' => 6259, 'modified' => null],
        ];
        $this->connection->projectSetIds = [6259];
        $this->connection->items[1] = [
            ['id' => 1, 'modified' => '2026-06-07 08:09:10'],
            ['id' => 2, 'modified' => null],
            ['id' => 3, 'modified' => 'not a date'],
        ];
        $this->cacheDir = sys_get_temp_dir() . '/dre-seo-output-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->generator(true)->destroyCache();
    }

    /** @return array<string,mixed> */
    private static function hints(): array
    {
        return [
            'item_chunk_size' => 2,
            'priority' => ['home' => '1.0', 'section' => '0.8', 'item' => '0.6', 'page' => '0.5', 'browse' => '0.4'],
            'changefreq' => ['home' => 'daily', 'item' => 'monthly', 'page' => 'monthly', 'browse' => 'weekly'],
        ];
    }

    /** @param array<string,mixed>|null $config */
    private function generator(bool $cached, ?array $config = null): SitemapGenerator
    {
        $logger = new MemoryLogger();
        return new SitemapGenerator(
            new SitemapRepository($this->connection, $logger),
            new UrlsetWriter(),
            new XmlCache($cached ? $this->cacheDir : null, $logger),
            $config ?? self::hints()
        );
    }

    /**
     * Build a document live, then twice through the cache (a miss, then a
     * hit): all three must be $expected.
     *
     * @param callable(SitemapGenerator,int):string $build given the generator and a TTL
     */
    private function assertDocument(string $expected, callable $build): void
    {
        $this->assertSame($expected, $build($this->generator(false), 0), 'live');
        $cached = $this->generator(true);
        $this->assertSame($expected, $build($cached, 3600), 'cache miss');
        $this->assertSame($expected, $build($this->generator(true), 3600), 'cache hit');
    }

    public function testTheIndex(): void
    {
        $this->assertDocument(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <sitemap><loc>https://example.test/sitemap-pages.xml</loc><lastmod>2026-06-25T08:52:27+00:00</lastmod></sitemap>
              <sitemap><loc>https://example.test/sitemap-item-sets.xml</loc></sitemap>
              <sitemap><loc>https://example.test/sitemap-items-1.xml</loc><lastmod>2026-10-07T12:00:00+00:00</lastmod></sitemap>
              <sitemap><loc>https://example.test/sitemap-items-2.xml</loc><lastmod>2026-10-07T12:00:00+00:00</lastmod></sitemap>
            </sitemapindex>

            XML,
            static fn (SitemapGenerator $generator, int $ttl): string => $generator->buildIndex('https://example.test', 1, $ttl, true)->xml
        );
    }

    public function testThePagesSitemap(): void
    {
        $navigation = [
            ['type' => 'page', 'data' => ['id' => 2], 'links' => [['type' => 'page', 'data' => ['id' => 3]]]],
            ['type' => 'url', 'data' => ['url' => 'https://example.org/']],
            ['type' => 'page', 'data' => ['id' => 2]],
        ];
        $this->assertDocument(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.test/s/amira/page/home</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>
              <url><loc>https://example.test/s/amira/page/research%20%26%20data</loc><changefreq>monthly</changefreq><priority>0.8</priority></url>
              <url><loc>https://example.test/s/amira/page/team</loc><lastmod>2026-03-04T05:06:07+00:00</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>
              <url><loc>https://example.test/s/amira/page/exposition%20sp%C3%A9ciale</loc><changefreq>monthly</changefreq><priority>0.4</priority></url>
            </urlset>

            XML,
            static fn (SitemapGenerator $generator, int $ttl): string => $generator->buildPages('https://example.test/s/amira', 1, $ttl, $navigation, 1, [5])->xml
        );
    }

    public function testTheItemSetsSitemap(): void
    {
        $this->assertDocument(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.test/s/amira/item-set/20</loc><lastmod>2026-05-06T07:08:09+00:00</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>
            </urlset>

            XML,
            static fn (SitemapGenerator $generator, int $ttl): string => $generator->buildItemSets('https://example.test/s/amira', 1, $ttl, true)->xml
        );
    }

    public function testAnItemsChunk(): void
    {
        $this->assertDocument(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.test/s/a&amp;b/item/1</loc><lastmod>2026-06-07T08:09:10+00:00</lastmod><changefreq>monthly</changefreq><priority>0.6</priority></url>
              <url><loc>https://example.test/s/a&amp;b/item/2</loc><changefreq>monthly</changefreq><priority>0.6</priority></url>
            </urlset>

            XML,
            static fn (SitemapGenerator $generator, int $ttl): string => $generator->buildItems('https://example.test/s/a&b', 1, 1, $ttl)->xml
        );
    }

    /** The shipped configuration: no hints, and a chunk with nothing in it is still a urlset. */
    public function testTheShippedConfigurationAndAnEmptyChunk(): void
    {
        $config = (require __DIR__ . '/../../config/module.config.php')['dre_seo']['sitemap'];

        $this->assertSame(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.test/s/amira/item/1</loc><lastmod>2026-06-07T08:09:10+00:00</lastmod></url>
              <url><loc>https://example.test/s/amira/item/2</loc></url>
              <url><loc>https://example.test/s/amira/item/3</loc></url>
            </urlset>

            XML,
            $this->generator(false, $config)->buildItems('https://example.test/s/amira', 1, 1, 0)->xml
        );
        $this->assertSame(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            </urlset>

            XML,
            $this->generator(false, $config)->buildItems('https://example.test/s/amira', 1, 2, 0)->xml
        );
    }
}
