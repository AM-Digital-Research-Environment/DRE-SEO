<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Doctrine\DBAL\Connection;

/**
 * Builds the sitemap index and the per-type child sitemaps for one site.
 *
 * Resource ids + modified timestamps are read with a single lean DBAL query per
 * type (public resources only, scoped to the site), so even ~9k items render in
 * well under a second. Output is cached to a writable directory with a TTL;
 * any cache failure silently falls back to live generation.
 *
 * URL construction is intentionally string-based (the caller passes the
 * already-canonical host and site roots) rather than invoking the URL helper
 * thousands of times.
 */
class SitemapGenerator
{
    /** Sitemaps protocol hard cap is 50,000 URLs / 50 MB per file. */
    private const MAX_URLS_PER_FILE = 50000;

    /**
     * @param array<string,mixed> $config the 'dre_seo.sitemap' config block
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $config,
        private readonly ?string $cacheDir,
    ) {
    }

    private function chunkSize(): int
    {
        $size = (int) ($this->config['item_chunk_size'] ?? self::MAX_URLS_PER_FILE);
        return ($size > 0 && $size <= self::MAX_URLS_PER_FILE) ? $size : self::MAX_URLS_PER_FILE;
    }

    // ─── Index ──────────────────────────────────────────────────────────────

    public function buildIndex(string $hostUrl, int $siteId, int $ttl): string
    {
        $cacheKey = $this->cacheKey($siteId, 'index', $hostUrl, (string) $this->chunkSize());
        return $this->cached($cacheKey, $ttl, function () use ($hostUrl, $siteId) {
            $children = ['sitemap-pages.xml', 'sitemap-item-sets.xml'];
            $chunks = max(1, (int) ceil($this->countItems($siteId) / $this->chunkSize()));
            for ($i = 1; $i <= $chunks; $i++) {
                $children[] = 'sitemap-items-' . $i . '.xml';
            }

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($children as $child) {
                $xml .= '  <sitemap><loc>' . $this->esc($hostUrl . '/' . $child) . '</loc>'
                    . '<lastmod>' . $now . '</lastmod></sitemap>' . "\n";
            }
            $xml .= '</sitemapindex>' . "\n";
            return $xml;
        });
    }

    // ─── Child sitemaps ───────────────────────────────────────────────────

    /**
     * Pages sitemap, driven by the site navigation so it mirrors the real site
     * structure: home first, then menu pages in order with a priority that
     * reflects their menu depth (top-level entries outrank submenu items), then
     * any remaining public pages (demoted, so nothing is silently dropped).
     *
     * @param string $hostUrl the scheme+host root (no path) where the home page
     *                        canonically lives
     * @param array<mixed> $navTree the site's o:navigation tree
     */
    public function buildPages(
        string $siteUrl,
        string $hostUrl,
        int $siteId,
        int $ttl,
        array $navTree = [],
        ?int $homepageId = null
    ): string {
        $cacheKey = $this->cacheKey($siteId, 'pages', $siteUrl, $hostUrl);
        return $this->cached($cacheKey, $ttl, function () use ($siteUrl, $hostUrl, $siteId, $navTree, $homepageId) {
            // All public pages, keyed by id.
            $pagesById = [];
            foreach ($this->fetchPages($siteId) as $row) {
                $pagesById[(int) $row['id']] = $row;
            }

            $urls = [];
            $emitted = [];
            $pageUrl = function (array $row, ?string $priority, ?string $changefreq) use ($siteUrl): array {
                return [
                    'loc'        => $siteUrl . '/page/' . rawurlencode((string) $row['slug']),
                    'lastmod'    => $this->w3c($row['modified'] ?? null),
                    'changefreq' => $changefreq,
                    'priority'   => $priority,
                ];
            };

            // Home first, at the bare domain root. The home page declares `/` as
            // its canonical (host root, site root and /page/{slug} all resolve
            // there and Google consolidates them onto the domain), so the sitemap
            // lists `/` to match — not the /page/{slug} URL, which would disagree
            // with that canonical. The homepage's own page entry is marked
            // emitted so the nav loop below skips it.
            $homeLoc = rtrim($hostUrl, '/') . '/';
            if ($homepageId !== null && isset($pagesById[$homepageId])) {
                $urls[] = [
                    'loc'        => $homeLoc,
                    'lastmod'    => $this->w3c($pagesById[$homepageId]['modified'] ?? null),
                    'changefreq' => $this->changefreq('home'),
                    'priority'   => $this->priority('home'),
                ];
                $emitted[$homepageId] = true;
            } else {
                $urls[] = [
                    'loc'        => $homeLoc,
                    'changefreq' => $this->changefreq('home'),
                    'priority'   => $this->priority('home'),
                ];
            }

            // Navigation pages, in menu order; priority by depth.
            foreach ($this->flattenNav($navTree) as $nav) {
                $id = $nav['id'];
                if (isset($emitted[$id]) || !isset($pagesById[$id])) {
                    continue;
                }
                $priority = $nav['depth'] === 0 ? $this->priority('section') : $this->priority('page');
                $urls[] = $pageUrl($pagesById[$id], $priority, $this->changefreq('page'));
                $emitted[$id] = true;
            }

            // Public pages not reachable from the navigation (e.g. a search
            // page) — kept for coverage but demoted.
            foreach ($pagesById as $id => $row) {
                if (isset($emitted[$id])) {
                    continue;
                }
                $urls[] = $pageUrl($row, $this->priority('browse'), $this->changefreq('page'));
            }

            return $this->renderUrlset($urls);
        });
    }

    /**
     * Flattens the Omeka navigation tree into an ordered list of page links with
     * their menu depth. Only `page`-type links are followed; url / browse links
     * are not emitted as canonical pages here.
     *
     * @param array<mixed> $tree
     * @param array<array{id:int,depth:int}> $acc
     * @return array<array{id:int,depth:int}>
     */
    private function flattenNav(array $tree, int $depth = 0, array &$acc = []): array
    {
        foreach ($tree as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (($link['type'] ?? null) === 'page' && isset($link['data']['id'])) {
                $acc[] = ['id' => (int) $link['data']['id'], 'depth' => $depth];
            }
            if (!empty($link['links']) && is_array($link['links'])) {
                $this->flattenNav($link['links'], $depth + 1, $acc);
            }
        }
        return $acc;
    }

    public function buildItemSets(string $siteUrl, int $siteId, int $ttl): string
    {
        $cacheKey = $this->cacheKey($siteId, 'item-sets', $siteUrl);
        return $this->cached($cacheKey, $ttl, function () use ($siteUrl, $siteId) {
            $urls = [];
            foreach ($this->fetchItemSets($siteId) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/item-set/' . (int) $row['id'],
                    'lastmod'    => $this->w3c($row['modified'] ?? null),
                    'changefreq' => $this->changefreq('browse'),
                    'priority'   => $this->priority('section'),
                ];
            }
            return $this->renderUrlset($urls);
        });
    }

    public function buildItems(string $siteUrl, int $siteId, int $chunk, int $ttl): string
    {
        $chunk = max(1, $chunk);
        $cacheKey = $this->cacheKey(
            $siteId,
            'items-' . $chunk,
            $siteUrl,
            (string) $this->chunkSize()
        );
        return $this->cached($cacheKey, $ttl, function () use ($siteUrl, $siteId, $chunk) {
            $size = $this->chunkSize();
            $offset = ($chunk - 1) * $size;
            $urls = [];
            foreach ($this->fetchItems($siteId, $offset, $size) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/item/' . (int) $row['id'],
                    'lastmod'    => $this->w3c($row['modified'] ?? null),
                    'changefreq' => $this->changefreq('item'),
                    'priority'   => $this->priority('item'),
                ];
            }
            return $this->renderUrlset($urls);
        });
    }

    public function itemChunkCount(int $siteId): int
    {
        return max(1, (int) ceil($this->countItems($siteId) / $this->chunkSize()));
    }

    public function clearCache(): void
    {
        if ($this->cacheDir === null || !is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @return array{items:int,itemSets:int,pages:int} */
    public function counts(int $siteId): array
    {
        return [
            'items'    => $this->countItems($siteId),
            'itemSets' => count($this->fetchItemSets($siteId)),
            'pages'    => count($this->fetchPages($siteId)), // home is one of these
        ];
    }

    // ─── Data (lean DBAL) ───────────────────────────────────────────────────

    private function countItems(int $siteId): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM resource r
                 JOIN item_site isi ON isi.item_id = r.id
                 WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s',
                ['t' => 'Omeka\\Entity\\Item', 's' => $siteId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return array<array{id:int,modified:?string}> */
    private function fetchItems(int $siteId, int $offset, int $limit): array
    {
        // LIMIT/OFFSET are inlined as already-cast integers: PDO refuses bound
        // parameters there under emulated prepares, and casting makes it safe.
        $limit = max(0, $limit);
        $offset = max(0, $offset);
        try {
            return $this->connection->fetchAllAssociative(
                'SELECT r.id, r.modified FROM resource r
                 JOIN item_site isi ON isi.item_id = r.id
                 WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s
                 ORDER BY r.id LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['t' => 'Omeka\\Entity\\Item', 's' => $siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<array{id:int,modified:?string}> */
    private function fetchItemSets(int $siteId): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                'SELECT r.id, r.modified FROM resource r
                 JOIN site_item_set sis ON sis.item_set_id = r.id
                 WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s
                 ORDER BY r.id',
                ['t' => 'Omeka\\Entity\\ItemSet', 's' => $siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<array{id:int,slug:string,modified:?string}> */
    private function fetchPages(int $siteId): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                'SELECT id, slug, modified FROM site_page
                 WHERE site_id = :s AND is_public = 1 ORDER BY id',
                ['s' => $siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─── XML rendering ──────────────────────────────────────────────────────

    /** @param array<array<string,?string>> $urls */
    private function renderUrlset(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . $this->esc((string) $u['loc']) . '</loc>';
            if (!empty($u['lastmod'])) {
                $xml .= '<lastmod>' . $this->esc((string) $u['lastmod']) . '</lastmod>';
            }
            if (!empty($u['changefreq'])) {
                $xml .= '<changefreq>' . $u['changefreq'] . '</changefreq>';
            }
            if (!empty($u['priority'])) {
                $xml .= '<priority>' . $u['priority'] . '</priority>';
            }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function w3c(?string $dbDatetime): ?string
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

    private function changefreq(string $kind): ?string
    {
        return $this->config['changefreq'][$kind] ?? null;
    }

    private function priority(string $kind): ?string
    {
        return $this->config['priority'][$kind] ?? null;
    }

    // ─── Cache ────────────────────────────────────────────────────────────

    private function cacheKey(int $siteId, string $section, string ...$scope): string
    {
        $key = sprintf('site-%d-%s', $siteId, $section);
        if ($scope !== []) {
            $key .= '-' . substr(hash('sha256', implode("\0", $scope)), 0, 12);
        }
        return $key;
    }

    /** @param callable():string $build */
    private function cached(string $key, int $ttl, callable $build): string
    {
        if ($this->cacheDir === null || $ttl <= 0) {
            return $build();
        }
        $file = $this->cacheDir . '/' . $key . '.xml';
        try {
            if (is_file($file) && (time() - filemtime($file)) < $ttl) {
                $cached = file_get_contents($file);
                if ($cached !== false) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            // fall through to live build
        }

        $xml = $build();

        try {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            if (is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
                file_put_contents($file, $xml, LOCK_EX);
            }
        } catch (\Throwable $e) {
            // caching is best-effort
        }
        return $xml;
    }
}
