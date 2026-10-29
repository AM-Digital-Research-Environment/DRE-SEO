<?php
declare(strict_types=1);

namespace DRESeo\Service\Sitemap;

/**
 * Renders the sitemaps.org XML: a `<sitemapindex>` of child documents, each
 * with its own optional lastmod, or a `<urlset>` of URLs with optional
 * lastmod, changefreq and priority.
 *
 * Deliberately string-built rather than DOM/XMLWriter — the output is a fixed,
 * shallow shape and the module carries no bundled vendor/ — but every value
 * that reaches the output goes through esc(), the config-sourced changefreq
 * and priority included. Those two used to be interpolated raw: safe in
 * practice, since they come from config rather than content, but a config
 * edit could have emitted XML no crawler would parse.
 *
 * Pure: no database, no filesystem, no clock. This is the class that produces
 * the actual protocol output, and so the one asserted against.
 */
final class UrlsetWriter
{
    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** The protocol's limits for one document. */
    private const MAX_URLS = 50000;
    private const MAX_BYTES = 50 * 1024 * 1024;

    /**
     * A sitemap index listing child documents.
     *
     * @param array<array{loc:string,lastmod?:?string}> $children
     */
    public function renderIndex(array $children): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="' . self::XMLNS . '">' . "\n";
        foreach ($children as $child) {
            $xml .= '  <sitemap><loc>' . $this->esc($child['loc']) . '</loc>'
                . (!empty($child['lastmod']) ? '<lastmod>' . $this->esc((string) $child['lastmod']) . '</lastmod>' : '')
                . '</sitemap>' . "\n";
        }
        return $xml . '</sitemapindex>' . "\n";
    }

    /**
     * @param array<array{loc:string,lastmod?:?string,changefreq?:?string,priority?:?string}> $urls
     * @throws \LengthException past 50,000 URLs or 50 MB, which no crawler reads
     */
    public function renderUrlset(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="' . self::XMLNS . '">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . $this->esc($url['loc']) . '</loc>';
            foreach (['lastmod', 'changefreq', 'priority'] as $tag) {
                if (!empty($url[$tag])) {
                    $xml .= '<' . $tag . '>' . $this->esc((string) $url[$tag]) . '</' . $tag . '>';
                }
            }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        if (count($urls) > self::MAX_URLS || strlen($xml) > self::MAX_BYTES) {
            throw new \LengthException('Sitemap exceeds the protocol limits; lower dre_seo.sitemap.item_chunk_size.');
        }
        return $xml;
    }

    /**
     * A database timestamp as a W3C datetime, or null when absent or
     * unparseable. Omeka stores `created` and `modified` as naive DATETIMEs
     * written in UTC — its bootstrap sets PHP's default timezone to UTC before
     * Doctrine formats them — so they are read back as UTC, never in the
     * server's zone.
     */
    public static function w3cDate(?string $dbDatetime): ?string
    {
        if (!$dbDatetime) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($dbDatetime, new \DateTimeZone('UTC')))->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
