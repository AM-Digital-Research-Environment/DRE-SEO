<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DOMDocument;
use DRESeo\Service\SitemapGenerator;

/**
 * The sitemap generator's SQL against a real DBAL connection (SQLite, in
 * memory, holding the fixture in OmekaTestCase), and the documents it builds.
 * Every test ends by checking that no query failed: the generator logs a
 * failure and degrades to an empty answer, which a count alone could miss.
 */
final class SitemapDatabaseTest extends OmekaTestCase
{
    private function generator(): SitemapGenerator
    {
        return $this->services->get(SitemapGenerator::class);
    }

    private function assertNoSitemapQueryFailed(): void
    {
        $this->assertSame(
            [],
            array_filter($this->logWriter->events, static fn (array $e): bool => str_contains((string) $e['message'], 'sitemap')),
            'A sitemap query failed: ' . json_encode(array_column($this->logWriter->events, 'message'))
        );
    }

    public function testTheCountsQueryTheRealConnection(): void
    {
        $this->assertSame(
            ['items' => 2, 'itemSets' => 4, 'pages' => 1],
            $this->generator()->counts(1),
            'Sitemap factory did not query the real DBAL connection correctly.'
        );
        $this->assertNoSitemapQueryFailed();
    }

    public function testTheProjectItemSetsLeaveTheSitemapInSql(): void
    {
        // The project item sets redirect, so the sitemap leaves them out.
        $itemSetsXml = $this->generator()->buildItemSets('https://example.test/s/amira', 1, 0, true);

        $message = "The item-set sitemap did not leave out the project item sets:\n" . $itemSetsXml;
        $this->assertStringContainsString('/item-set/3<', $itemSetsXml, $message);
        $this->assertStringContainsString('/item-set/22<', $itemSetsXml, $message);
        $this->assertStringNotContainsString('/item-set/20<', $itemSetsXml, $message);
        $this->assertStringNotContainsString('/item-set/21<', $itemSetsXml, $message);
        $this->assertSame(2, $this->generator()->counts(1, true)['itemSets'], 'The project item sets were counted.');
        $this->assertNoSitemapQueryFailed();
    }

    /**
     * Every document over the fixture, byte for byte: the SQL's answers as
     * crawlers are served them, pinned against a refactoring of the queries.
     */
    public function testTheDocumentsOverTheFixtureByteForByte(): void
    {
        $generator = $this->generator();
        $site = 'https://example.test/s/amira';
        $head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $urlset = $head . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $this->assertSame(
            $head . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . '  <sitemap><loc>https://example.test/sitemap-pages.xml</loc><lastmod>2026-01-03T00:00:00+00:00</lastmod></sitemap>' . "\n"
            . '  <sitemap><loc>https://example.test/sitemap-item-sets.xml</loc><lastmod>2026-01-02T00:00:00+00:00</lastmod></sitemap>' . "\n"
            . '  <sitemap><loc>https://example.test/sitemap-items-1.xml</loc><lastmod>2026-01-01T00:00:00+00:00</lastmod></sitemap>' . "\n"
            . '</sitemapindex>' . "\n",
            (string) $generator->buildIndex('https://example.test', 1, 0, true)
        );
        $this->assertSame(
            $urlset . '  <url><loc>' . $site . '/page/home</loc><lastmod>2026-01-03T00:00:00+00:00</lastmod></url>' . "\n" . '</urlset>' . "\n",
            (string) $generator->buildPages($site, 1, 0, [], 10)
        );
        $this->assertSame(
            $urlset
            . '  <url><loc>' . $site . '/item-set/3</loc><lastmod>2026-01-02T00:00:00+00:00</lastmod></url>' . "\n"
            . '  <url><loc>' . $site . '/item-set/22</loc></url>' . "\n"
            . '</urlset>' . "\n",
            (string) $generator->buildItemSets($site, 1, 0, true)
        );
        $this->assertSame(
            $urlset
            . '  <url><loc>' . $site . '/item-set/3</loc><lastmod>2026-01-02T00:00:00+00:00</lastmod></url>' . "\n"
            . '  <url><loc>' . $site . '/item-set/20</loc></url>' . "\n"
            . '  <url><loc>' . $site . '/item-set/21</loc></url>' . "\n"
            . '  <url><loc>' . $site . '/item-set/22</loc></url>' . "\n"
            . '</urlset>' . "\n",
            (string) $generator->buildItemSets($site, 1, 0)
        );
        $this->assertSame(
            $urlset
            . '  <url><loc>' . $site . '/item/1</loc><lastmod>2026-01-01T00:00:00+00:00</lastmod></url>' . "\n"
            . '  <url><loc>' . $site . '/item/2</loc></url>' . "\n"
            . '</urlset>' . "\n",
            (string) $generator->buildItems($site, 1, 1, 0)
        );
        $this->assertSame(1, $generator->itemChunkCount(1));
        $this->assertNoSitemapQueryFailed();
    }

    public function testTheIndexIsValidXmlAndTheItemsSitemapListsOnlyPublicItems(): void
    {
        $generator = $this->generator();
        $generator->buildIndex('https://example.test', 1, 0, true);
        $this->assertNoSitemapQueryFailed();

        $indexXml = $generator->buildIndex('https://example.test', 1, 60);
        $itemsXml = $generator->buildItems('https://example.test/s/amira', 1, 1, 60);
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($indexXml), 'Sitemap index is invalid XML.');
        $this->assertStringContainsString('/item/1', $itemsXml, 'Public items are missing.');
        $this->assertStringContainsString('/item/2', $itemsXml, 'Public items are missing.');
        $this->assertStringNotContainsString('/item/4', $itemsXml, 'A private item leaked into the sitemap.');
    }
}
