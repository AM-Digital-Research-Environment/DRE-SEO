<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * The site page that presents an item set's kind of record — *Publications*
 * for the publications item set, *Research projects* for the projects — which
 * the breadcrumb names between the home page and the record.
 *
 * The map (item set id => page slug) ships with the instance's defaults
 * (`dre_seo.breadcrumb.landing_pages`); the site setting
 * `dre_seo_landing_pages` overrides it line by line, as `id = slug`, and
 * `id =` with no slug drops a default. A page counts only when it exists in
 * the site and is public; otherwise the item set has no landing page and the
 * breadcrumb names the item set itself.
 */
final class LandingPages
{
    /** Site setting: one `item-set-id = page-slug` per line. */
    public const SETTING = 'dre_seo_landing_pages';

    /** A setting line: an item set id, `=`, and a page slug (or nothing). */
    private const LINE = '/\A(\d+)\s*=\s*([A-Za-z0-9_-]*)\z/';

    /** @var array<int,array<int,string>> site id => item set id => slug */
    private array $maps = [];

    /** @var array<int,array<string,array{name:string,url:string}>> site id => slug => crumb */
    private array $pages = [];

    /**
     * @param array<int|string,string|null> $defaults item set id => page slug
     * @param \Closure(int):mixed|null      $siteSetting the site's
     *        dre_seo_landing_pages setting, by site id
     */
    public function __construct(
        private readonly array $defaults = [],
        private readonly ?\Closure $siteSetting = null,
    ) {
    }

    /**
     * The landing page of the first of $itemSets that has one in the site, as
     * a crumb: the page's title and absolute URL.
     *
     * @param iterable<ItemSetRepresentation> $itemSets
     * @return array{name:string,url:string}|null
     */
    public function forItemSets(iterable $itemSets, SiteRepresentation $site): ?array
    {
        $map = $this->map($site);
        if ($map === []) {
            return null;
        }
        foreach ($itemSets as $itemSet) {
            $slug = $map[$itemSet->id()] ?? null;
            if ($slug === null) {
                continue;
            }
            $page = $this->publicPages($site)[$slug] ?? null;
            if ($page !== null) {
                return $page;
            }
        }
        return null;
    }

    /**
     * The map in force for a site: the defaults, with the site's own lines
     * over them.
     *
     * @return array<int,string> item set id => page slug
     */
    public function map(SiteRepresentation $site): array
    {
        $siteId = (int) $site->id();
        if (isset($this->maps[$siteId])) {
            return $this->maps[$siteId];
        }
        $map = [];
        foreach ($this->defaults as $id => $slug) {
            $map[(int) $id] = trim((string) $slug);
        }
        $custom = null;
        if ($this->siteSetting !== null) {
            try {
                $custom = ($this->siteSetting)($siteId);
            } catch (\Throwable $e) {
                $custom = null;
            }
        }
        if (is_string($custom)) {
            foreach (self::parse($custom) as $id => $slug) {
                $map[$id] = $slug;
            }
        }
        return $this->maps[$siteId] = array_filter($map, static fn (string $slug): bool => $slug !== '');
    }

    /**
     * The setting's lines as a map. An empty slug is kept, so a line can
     * drop a default; blank lines, `#` comments and malformed lines are
     * skipped.
     *
     * @return array<int,string>
     */
    public static function parse(string $text): array
    {
        $map = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match(self::LINE, $line, $m)) {
                $map[(int) $m[1]] = $m[2];
            }
        }
        return $map;
    }

    /**
     * The lines of the setting that are neither blank, comments nor
     * `id = slug`, for the form to reject.
     *
     * @return string[]
     */
    public static function invalidLines(string $text): array
    {
        $invalid = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#') && !preg_match(self::LINE, $line)) {
                $invalid[] = $line;
            }
        }
        return $invalid;
    }

    /**
     * A map as setting lines, for the form's placeholder.
     *
     * @param array<int|string,string|null> $map
     */
    public static function format(array $map): string
    {
        $lines = [];
        foreach ($map as $id => $slug) {
            $lines[] = rtrim(sprintf('%d = %s', (int) $id, trim((string) $slug)));
        }
        return implode("\n", $lines);
    }

    /** @return array<string,array{name:string,url:string}> the site's public pages by slug */
    private function publicPages(SiteRepresentation $site): array
    {
        $siteId = (int) $site->id();
        if (isset($this->pages[$siteId])) {
            return $this->pages[$siteId];
        }
        $pages = [];
        try {
            foreach ($site->pages() as $page) {
                if (method_exists($page, 'isPublic') && !$page->isPublic()) {
                    continue;
                }
                $name = trim((string) $page->title());
                $url = (string) $page->siteUrl($site->slug(), true);
                if ($name !== '' && $url !== '') {
                    $pages[(string) $page->slug()] = ['name' => $name, 'url' => $url];
                }
            }
        } catch (\Throwable $e) {
            $pages = [];
        }
        return $this->pages[$siteId] = $pages;
    }
}
