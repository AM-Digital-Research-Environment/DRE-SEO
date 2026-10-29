<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;

/**
 * Builds the sitemap index and the per-type child sitemaps for one site.
 *
 * Resource ids + modified timestamps are read with a single lean DBAL query per
 * type (public resources only, scoped to the site), so even ~9k items render in
 * well under a second. Output is cached to a writable directory with a TTL;
 * cache failures fall back to live generation and are logged for diagnosis.
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
     * Keeps an item set (`r`) only when it stands for no public research
     * project: none of its public `dcterms:isPartOf` values links to a public
     * `foaf:Project` item, and no public `foaf:Project` item carries its
     * `dre:id` — the rule {@see ProjectResolver::projectOfItemSet()} applies,
     * as two correlated subqueries of the one query. Binds :item.
     */
    private const NOT_A_PROJECT_SET = "
        AND NOT EXISTS (
            SELECT 1 FROM value v
            JOIN property p ON p.id = v.property_id
            JOIN vocabulary pv ON pv.id = p.vocabulary_id
            JOIN resource pr ON pr.id = v.value_resource_id
            JOIN resource_class c ON c.id = pr.resource_class_id
            JOIN vocabulary cv ON cv.id = c.vocabulary_id
            WHERE v.resource_id = r.id AND v.is_public = 1
              AND pv.prefix = 'dcterms' AND p.local_name = 'isPartOf'
              AND pr.resource_type = :item AND pr.is_public = 1
              AND cv.prefix = 'foaf' AND c.local_name = 'Project'
        )
        AND NOT EXISTS (
            SELECT 1 FROM value sv
            JOIN property sp ON sp.id = sv.property_id
            JOIN vocabulary spv ON spv.id = sp.vocabulary_id
            JOIN value iv ON iv.property_id = sv.property_id AND iv.value = sv.value AND iv.is_public = 1
            JOIN resource ir ON ir.id = iv.resource_id
            JOIN resource_class ic ON ic.id = ir.resource_class_id
            JOIN vocabulary icv ON icv.id = ic.vocabulary_id
            WHERE sv.resource_id = r.id AND sv.is_public = 1
              AND spv.prefix = 'dre' AND sp.local_name = 'id'
              AND ir.resource_type = :item AND ir.is_public = 1
              AND icv.prefix = 'foaf' AND ic.local_name = 'Project'
        )";

    /**
     * @param array<string,mixed> $config the 'dre_seo.sitemap' config block
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $config,
        private readonly ?string $cacheDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function chunkSize(): int
    {
        $size = (int) ($this->config['item_chunk_size'] ?? self::MAX_URLS_PER_FILE);
        return ($size > 0 && $size <= self::MAX_URLS_PER_FILE) ? $size : self::MAX_URLS_PER_FILE;
    }

    // ─── Index ──────────────────────────────────────────────────────────────

    /**
     * @param bool $excludeProjectSets leave out the item sets whose page
     *        redirects to their project ({@see buildItemSets()})
     */
    public function buildIndex(string $hostUrl, int $siteId, int $ttl, bool $excludeProjectSets = false): string
    {
        $cacheKey = $this->cacheKey($siteId, 'index', $hostUrl, (string) $this->chunkSize(), $excludeProjectSets ? 'projects-out' : '');
        return $this->cached($cacheKey, $ttl, function () use ($hostUrl, $siteId, $excludeProjectSets) {
            // Each child's lastmod is the latest change among the URLs it
            // lists. The generation time would change on every rebuild, and
            // Google and Bing both stop trusting a lastmod that does.
            $children = [
                'sitemap-pages.xml' => $this->latestPageChange($siteId),
                'sitemap-item-sets.xml' => $this->latestItemSetChange($siteId, $excludeProjectSets),
            ];
            $size = $this->chunkSize();
            $chunks = max(1, (int) ceil($this->countItems($siteId) / $size));
            for ($i = 1; $i <= $chunks; $i++) {
                $children['sitemap-items-' . $i . '.xml'] = $this->latestItemChange($siteId, ($i - 1) * $size, $size);
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($children as $child => $modified) {
                $lastmod = $this->w3c($modified);
                $xml .= '  <sitemap><loc>' . $this->esc($hostUrl . '/' . $child) . '</loc>'
                    . ($lastmod !== null ? '<lastmod>' . $lastmod . '</lastmod>' : '')
                    . '</sitemap>' . "\n";
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
     * @param string       $siteUrl    the site root, `https://host/s/{slug}`
     * @param array<mixed> $navTree    the site's o:navigation tree
     * @param int|null     $homepageId the page the site root redirects to ({@see SiteHome})
     * @param int[]        $noindexIds pages an editor set to noindex, which a
     *                                 sitemap must not list
     */
    public function buildPages(
        string $siteUrl,
        int $siteId,
        int $ttl,
        array $navTree = [],
        ?int $homepageId = null,
        array $noindexIds = []
    ): string {
        $noindexIds = array_values(array_unique(array_map('intval', $noindexIds)));
        sort($noindexIds);
        $cacheKey = $this->cacheKey($siteId, 'pages', $siteUrl, (string) $homepageId, implode(',', $noindexIds));
        return $this->cached($cacheKey, $ttl, function () use ($siteUrl, $siteId, $navTree, $homepageId, $noindexIds) {
            // All public pages, keyed by id, less those kept out of the index.
            $pagesById = [];
            foreach ($this->fetchPages($siteId) as $row) {
                $pagesById[(int) $row['id']] = $row;
            }
            foreach ($noindexIds as $id) {
                unset($pagesById[$id]);
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

            // Home first, at its own page URL — the URL its canonical names.
            // The host root and the site root only redirect there, so they are
            // not listed.
            if ($homepageId !== null && isset($pagesById[$homepageId])) {
                $urls[] = $pageUrl($pagesById[$homepageId], $this->priority('home'), $this->changefreq('home'));
                $emitted[$homepageId] = true;
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

    /**
     * Item-set pages. With $excludeProjectSets, an item set that stands for a
     * public research project is left out: its page redirects (301) to the
     * project item, which the items sitemap lists ({@see ProjectResolver}).
     */
    public function buildItemSets(string $siteUrl, int $siteId, int $ttl, bool $excludeProjectSets = false): string
    {
        $cacheKey = $this->cacheKey($siteId, 'item-sets', $siteUrl, $excludeProjectSets ? 'projects-out' : '');
        return $this->cached($cacheKey, $ttl, function () use ($siteUrl, $siteId, $excludeProjectSets) {
            $urls = [];
            foreach ($this->fetchItemSets($siteId, $excludeProjectSets) as $row) {
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
        $files = glob($this->cacheDir . '/*.xml');
        if ($files === false) {
            $this->logger->warn('DRESeo: failed to list sitemap cache files.', [
                'cache_dir' => $this->cacheDir,
            ]);
            return;
        }
        foreach ($files as $file) {
            if (!@unlink($file)) {
                $this->logger->warn('DRESeo: failed to remove a sitemap cache file.', [
                    'file' => $file,
                ]);
            }
        }
    }

    /**
     * Clear the cache and remove its directory, whatever else is in it
     * (uninstall). A directory left behind is logged: the module is going, but
     * a stale files/dre-seo-cache would be served again by a reinstall.
     */
    public function destroyCache(): void
    {
        $this->clearCache();
        if ($this->cacheDir === null || !is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (!@rmdir($this->cacheDir)) {
            $this->logger->warn('DRESeo: failed to remove the sitemap cache directory.', [
                'cache_dir' => $this->cacheDir,
            ]);
        }
    }

    /** @return array{items:int,itemSets:int,pages:int} */
    public function counts(int $siteId, bool $excludeProjectSets = false): array
    {
        return [
            'items'    => $this->countItems($siteId),
            'itemSets' => count($this->fetchItemSets($siteId, $excludeProjectSets)),
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
            $this->logDatabaseFailure('count public items', $siteId, $e);
            return 0;
        }
    }

    /** The latest modification among one chunk of public items, or null. */
    private function latestItemChange(int $siteId, int $offset, int $limit): ?string
    {
        return $this->latest(
            'SELECT MAX(t.modified) FROM (SELECT r.modified FROM resource r
             JOIN item_site isi ON isi.item_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s
             ORDER BY r.id LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset) . ') t',
            ['t' => 'Omeka\\Entity\\Item', 's' => $siteId],
            $siteId
        );
    }

    private function latestItemSetChange(int $siteId, bool $excludeProjectSets = false): ?string
    {
        return $this->latest(
            'SELECT MAX(r.modified) FROM resource r
             JOIN site_item_set sis ON sis.item_set_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s'
             . ($excludeProjectSets ? self::NOT_A_PROJECT_SET : ''),
            $this->itemSetParams($siteId, $excludeProjectSets),
            $siteId
        );
    }

    private function latestPageChange(int $siteId): ?string
    {
        return $this->latest(
            'SELECT MAX(modified) FROM site_page WHERE site_id = :s AND is_public = 1',
            ['s' => $siteId],
            $siteId
        );
    }

    /** @param array<string,mixed> $params */
    private function latest(string $query, array $params, int $siteId): ?string
    {
        try {
            $value = $this->connection->fetchOne($query, $params);
        } catch (\Throwable $e) {
            $this->logDatabaseFailure('read a sitemap lastmod', $siteId, $e);
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
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
            $this->logDatabaseFailure('fetch public items', $siteId, $e);
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function itemSetParams(int $siteId, bool $excludeProjectSets): array
    {
        $params = ['t' => 'Omeka\\Entity\\ItemSet', 's' => $siteId];
        if ($excludeProjectSets) {
            $params['item'] = 'Omeka\\Entity\\Item';
        }
        return $params;
    }

    /** @return array<array{id:int,modified:?string}> */
    private function fetchItemSets(int $siteId, bool $excludeProjectSets = false): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                'SELECT r.id, r.modified FROM resource r
                 JOIN site_item_set sis ON sis.item_set_id = r.id
                 WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s'
                 . ($excludeProjectSets ? self::NOT_A_PROJECT_SET : '')
                 . ' ORDER BY r.id',
                $this->itemSetParams($siteId, $excludeProjectSets)
            );
        } catch (\Throwable $e) {
            $this->logDatabaseFailure('fetch public item sets', $siteId, $e);
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
            $this->logDatabaseFailure('fetch public site pages', $siteId, $e);
            return [];
        }
    }

    private function logDatabaseFailure(string $operation, int $siteId, \Throwable $exception): void
    {
        $this->logger->err(sprintf('DRESeo: failed to %s for sitemap generation.', $operation), [
            'site_id' => $siteId,
            'exception' => $exception,
        ]);
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

    /**
     * A database timestamp as a W3C datetime. Omeka stores `created` and
     * `modified` as naive DATETIMEs written in UTC — its bootstrap sets PHP's
     * default timezone to UTC before Doctrine formats them — so they are read
     * back as UTC, never in the server's zone.
     */
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
            if (is_file($file)) {
                $modified = @filemtime($file);
                if ($modified === false) {
                    $this->logger->warn('DRESeo: failed to read a sitemap cache timestamp.', [
                        'file' => $file,
                    ]);
                } elseif ((time() - $modified) < $ttl) {
                    $cached = @file_get_contents($file);
                    if ($cached !== false) {
                        return $cached;
                    }
                    $this->logger->warn('DRESeo: failed to read a sitemap cache file; generating live output.', [
                        'file' => $file,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warn('DRESeo: failed to read a sitemap cache file; generating live output.', [
                'file' => $file,
                'exception' => $e,
            ]);
        }

        $xml = $build();

        try {
            if (!is_dir($this->cacheDir)) {
                if (!@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
                    $this->logger->warn('DRESeo: failed to create the sitemap cache directory.', [
                        'cache_dir' => $this->cacheDir,
                    ]);
                }
            }
            if (is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
                if (@file_put_contents($file, $xml, LOCK_EX) === false) {
                    $this->logger->warn('DRESeo: failed to write a sitemap cache file.', [
                        'file' => $file,
                    ]);
                }
            } elseif (is_dir($this->cacheDir)) {
                $this->logger->warn('DRESeo: sitemap cache directory is not writable.', [
                    'cache_dir' => $this->cacheDir,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warn('DRESeo: failed to write sitemap cache; returning live output.', [
                'file' => $file,
                'exception' => $e,
            ]);
        }
        return $xml;
    }
}
