<?php
declare(strict_types=1);

namespace DRESeo\Test\Service\Sitemap;

use DRESeo\Service\Sitemap\UrlsetWriter;
use PHPUnit\Framework\TestCase;

/**
 * The sitemap's actual protocol output, apart from the queries and the cache
 * it used to be tangled up with.
 */
final class UrlsetWriterTest extends TestCase
{
    private UrlsetWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new UrlsetWriter();
    }

    private function xml(string $document): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($document);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($parsed, 'document is not well-formed XML');

        return $parsed;
    }

    public function testEmptyUrlsetIsStillValid(): void
    {
        $document = $this->writer->renderUrlset([]);
        $parsed = $this->xml($document);

        $this->assertSame('urlset', $parsed->getName());
        $this->assertCount(0, $parsed->children());
        // Chunk 1 is always served even with no items, so this shape matters.
        $this->assertStringContainsString('http://www.sitemaps.org/schemas/sitemap/0.9', $document);
    }

    public function testUrlCarriesEveryOptionalElementInProtocolOrder(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/42',
            'priority'   => '0.6',
            'changefreq' => 'monthly',
            'lastmod'    => '2026-01-02T03:04:05+00:00',
        ]]);

        $this->assertStringContainsString(
            '<url><loc>https://data.africamultiple.uni-bayreuth.de/s/amira/item/42</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod><changefreq>monthly</changefreq><priority>0.6</priority></url>',
            $document
        );
        $this->assertSame('0.6', (string) $this->xml($document)->url[0]->priority);
    }

    public function testOptionalElementsAreOmittedWhenNull(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://example.org/item/1',
            'lastmod'    => null,
            'changefreq' => null,
            'priority'   => null,
        ]]);

        $this->assertStringNotContainsString('<lastmod>', $document);
        $this->assertStringNotContainsString('<changefreq>', $document);
        $this->assertStringNotContainsString('<priority>', $document);
    }

    /**
     * An ampersand in a URL (a site slug, a faceted link) must not break the
     * document — this is why every value goes through esc().
     */
    public function testValuesAreEscaped(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc' => 'https://example.org/item?a=1&b=2',
        ]]);

        $this->assertStringContainsString('a=1&amp;b=2', $document);
        $this->assertSame('https://example.org/item?a=1&b=2', (string) $this->xml($document)->url[0]->loc);
    }

    /** changefreq and priority used to be interpolated raw. */
    public function testConfigSuppliedValuesAreEscapedToo(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://example.org/',
            'changefreq' => 'daily & nightly',
            'priority'   => '<1.0',
        ]]);

        $parsed = $this->xml($document);
        $this->assertSame('daily & nightly', (string) $parsed->url[0]->changefreq);
        $this->assertSame('<1.0', (string) $parsed->url[0]->priority);
    }

    public function testTheIndexDatesEachChildByItsOwnLastmod(): void
    {
        $document = $this->writer->renderIndex([
            ['loc' => 'https://example.org/sitemap-pages.xml', 'lastmod' => '2026-07-25T00:00:00+00:00'],
            ['loc' => 'https://example.org/sitemap-item-sets.xml', 'lastmod' => null],
            ['loc' => 'https://example.org/sitemap-items-1.xml'],
        ]);
        $parsed = $this->xml($document);

        $this->assertSame('sitemapindex', $parsed->getName());
        $this->assertCount(3, $parsed->sitemap);
        $this->assertSame('2026-07-25T00:00:00+00:00', (string) $parsed->sitemap[0]->lastmod);
        $this->assertCount(0, $parsed->sitemap[1]->lastmod);
        $this->assertCount(0, $parsed->sitemap[2]->lastmod);
    }

    /** A document no crawler would read is refused rather than served. */
    public function testAUrlsetPastTheProtocolLimitIsRefused(): void
    {
        $urls = array_fill(0, 50001, ['loc' => 'https://example.org/item/1']);

        $this->expectException(\LengthException::class);
        $this->writer->renderUrlset($urls);
    }

    public function testW3cDateParsesDatabaseTimestampsAsUtc(): void
    {
        $this->assertSame('2026-01-02T03:04:05+00:00', UrlsetWriter::w3cDate('2026-01-02 03:04:05'));
        $this->assertNull(UrlsetWriter::w3cDate(null));
        $this->assertNull(UrlsetWriter::w3cDate(''));
        $this->assertNull(UrlsetWriter::w3cDate('not a date'));
    }
}
