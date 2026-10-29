<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Sitemap\SitemapDocument;
use DRESeo\Service\Sitemap\SitemapRepositoryInterface;
use DRESeo\Service\Sitemap\UrlsetWriter;
use DRESeo\Service\Sitemap\XmlCache;

/**
 * Builds the sitemap index and the per-type child sitemaps for one site.
 *
 * This class is the *policy*: which URLs belong in which document, in what
 * order, with which lastmod and hints, under which cache key. The three
 * mechanisms it once carried inline live beside it — {@see Sitemap\SitemapRepository}
 * (the lean DBAL queries, the project item-set exclusion among them),
 * {@see UrlsetWriter} (the XML) and {@see XmlCache} (the TTL file cache) — so
 * the part that produces the protocol output is pure and testable.
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
        private readonly SitemapRepositoryInterface $repository,
        private readonly UrlsetWriter $writer,
        private readonly XmlCache $cache,
        private readonly array $config,
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
    public function buildIndex(string $hostUrl, int $siteId, int $ttl, bool $excludeProjectSets = false): SitemapDocument
    {
        $cacheKey = $this->cacheKey($siteId, 'index', $hostUrl, (string) $this->chunkSize(), $excludeProjectSets ? 'projects-out' : '');
        return $this->cache->remember($cacheKey, $ttl, function () use ($hostUrl, $siteId, $excludeProjectSets): string {
            // Each child's lastmod is the latest change among the URLs it
            // lists. The generation time would change on every rebuild, and
            // Google and Bing both stop trusting a lastmod that does.
            $children = [
                ['loc' => $hostUrl . '/sitemap-pages.xml', 'lastmod' => UrlsetWriter::w3cDate($this->repository->latestPageChange($siteId))],
                ['loc' => $hostUrl . '/sitemap-item-sets.xml', 'lastmod' => UrlsetWriter::w3cDate($this->repository->latestItemSetChange($siteId, $excludeProjectSets))],
            ];
            $size = $this->chunkSize();
            $chunks = max(1, (int) ceil($this->repository->countItems($siteId) / $size));
            for ($i = 1; $i <= $chunks; $i++) {
                $children[] = [
                    'loc' => $hostUrl . '/sitemap-items-' . $i . '.xml',
                    'lastmod' => UrlsetWriter::w3cDate($this->repository->latestItemChange($siteId, ($i - 1) * $size, $size)),
                ];
            }
            return $this->writer->renderIndex($children);
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
     * @param int[]        $noindexIds pages an editor set to noindex
     *                                 ({@see PageSeoStore::noindexPageIds()}),
     *                                 which a sitemap must not list; part of
     *                                 the cache key, so a change shows at once
     */
    public function buildPages(
        string $siteUrl,
        int $siteId,
        int $ttl,
        array $navTree = [],
        ?int $homepageId = null,
        array $noindexIds = []
    ): SitemapDocument {
        $noindexIds = array_values(array_unique(array_map('intval', $noindexIds)));
        sort($noindexIds);
        $cacheKey = $this->cacheKey($siteId, 'pages', $siteUrl, (string) $homepageId, implode(',', $noindexIds));
        return $this->cache->remember($cacheKey, $ttl, function () use ($siteUrl, $siteId, $navTree, $homepageId, $noindexIds): string {
            // All public pages, keyed by id, less those kept out of the index.
            $pagesById = [];
            foreach ($this->repository->fetchPages($siteId) as $row) {
                $pagesById[(int) $row['id']] = $row;
            }
            foreach ($noindexIds as $id) {
                unset($pagesById[$id]);
            }

            $urls = [];
            $emitted = [];
            $pageUrl = fn (array $row, ?string $priority, ?string $changefreq): array => [
                'loc'        => $siteUrl . '/page/' . rawurlencode((string) $row['slug']),
                'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                'changefreq' => $changefreq,
                'priority'   => $priority,
            ];

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
                if (!isset($emitted[$id])) {
                    $urls[] = $pageUrl($row, $this->priority('browse'), $this->changefreq('page'));
                }
            }

            return $this->writer->renderUrlset($urls);
        });
    }

    /**
     * Item-set pages. With $excludeProjectSets, an item set that stands for a
     * public research project is left out: its page redirects (301) to the
     * project item, which the items sitemap lists ({@see ProjectResolver}).
     */
    public function buildItemSets(string $siteUrl, int $siteId, int $ttl, bool $excludeProjectSets = false): SitemapDocument
    {
        $cacheKey = $this->cacheKey($siteId, 'item-sets', $siteUrl, $excludeProjectSets ? 'projects-out' : '');
        return $this->cache->remember($cacheKey, $ttl, function () use ($siteUrl, $siteId, $excludeProjectSets): string {
            $urls = [];
            foreach ($this->repository->fetchItemSets($siteId, $excludeProjectSets) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/item-set/' . (int) $row['id'],
                    'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                    'changefreq' => $this->changefreq('browse'),
                    'priority'   => $this->priority('section'),
                ];
            }
            return $this->writer->renderUrlset($urls);
        });
    }

    public function buildItems(string $siteUrl, int $siteId, int $chunk, int $ttl): SitemapDocument
    {
        $chunk = max(1, $chunk);
        $cacheKey = $this->cacheKey($siteId, 'items-' . $chunk, $siteUrl, (string) $this->chunkSize());
        return $this->cache->remember($cacheKey, $ttl, function () use ($siteUrl, $siteId, $chunk): string {
            $size = $this->chunkSize();
            $urls = [];
            foreach ($this->repository->fetchItems($siteId, ($chunk - 1) * $size, $size) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/item/' . (int) $row['id'],
                    'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                    'changefreq' => $this->changefreq('item'),
                    'priority'   => $this->priority('item'),
                ];
            }
            return $this->writer->renderUrlset($urls);
        });
    }

    public function itemChunkCount(int $siteId): int
    {
        return max(1, (int) ceil($this->repository->countItems($siteId) / $this->chunkSize()));
    }

    /**
     * What the dashboard reports the sitemap covering. A count the database
     * cannot answer is 0 — the repository has logged why — so the dashboard
     * still renders.
     *
     * @return array{items:int,itemSets:int,pages:int}
     */
    public function counts(int $siteId, bool $excludeProjectSets = false): array
    {
        return [
            'items'    => self::orZero(fn (): int => $this->repository->countItems($siteId)),
            'itemSets' => self::orZero(fn (): int => count($this->repository->fetchItemSets($siteId, $excludeProjectSets))),
            'pages'    => self::orZero(fn (): int => count($this->repository->fetchPages($siteId))), // home is one of these
        ];
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /** Clear the cache and remove its directory (uninstall). */
    public function destroyCache(): void
    {
        $this->cache->destroy();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** @param callable():int $count */
    private static function orZero(callable $count): int
    {
        try {
            return $count();
        } catch (\Throwable $e) {
            return 0;
        }
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
     * Cache key, namespaced by site id, and scoped by whatever else changes
     * the document's bytes: the host and site roots, the chunk size, the home
     * page, the noindex pages, the project exclusion.
     */
    private function cacheKey(int $siteId, string $section, string ...$scope): string
    {
        $key = sprintf('site-%d-%s', $siteId, $section);
        if ($scope !== []) {
            $key .= '-' . substr(hash('sha256', implode("\0", $scope)), 0, 12);
        }
        return $key;
    }

    private function changefreq(string $kind): ?string
    {
        return $this->config['changefreq'][$kind] ?? null;
    }

    private function priority(string $kind): ?string
    {
        return $this->config['priority'][$kind] ?? null;
    }
}
