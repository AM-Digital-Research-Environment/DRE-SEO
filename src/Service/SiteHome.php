<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * The site's home page, and the one URL every signal names it by.
 *
 * Omeka never renders a page at the host root or the site root: `/` redirects
 * to `/s/{slug}`, which redirects to the configured homepage page — or, when
 * none is configured, to the first page in the navigation (core's
 * Site\IndexController::indexAction). So the home page lives at
 * `/s/{slug}/page/{slug}`, and that page URL is its canonical, its sitemap
 * `<loc>`, its IndexNow URL and the `url` of the WebSite node, the breadcrumb
 * root and every `isPartOf` that names the site. A canonical at `/` or at the
 * site root would point at a redirect chain, and `/s/{slug}/` (with the
 * slash) is a 404.
 */
final class SiteHome
{
    /** The page the site root redirects to: the configured homepage, else the first linked page. */
    public static function page(SiteRepresentation $site): ?object
    {
        try {
            $homepage = method_exists($site, 'homepage') ? $site->homepage() : null;
            if ($homepage) {
                return $homepage;
            }
            $linked = method_exists($site, 'linkedPages') ? $site->linkedPages() : [];
            $first = is_array($linked) && $linked ? reset($linked) : null;
            return is_object($first) ? $first : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The home page's id, or null when the site has no page to land on. */
    public static function pageId(SiteRepresentation $site): ?int
    {
        $page = self::page($site);
        try {
            return $page && method_exists($page, 'id') ? (int) $page->id() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The home page's absolute URL. Null only when the site has no page at
     * all, and then the caller falls back to the site root.
     */
    public static function url(SiteRepresentation $site): ?string
    {
        $page = self::page($site);
        if ($page === null || !method_exists($page, 'siteUrl')) {
            return null;
        }
        try {
            $url = (string) $page->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
        return $url !== '' ? $url : null;
    }

    /**
     * The home page's URL, else — for a site with no page at all — the site
     * root, absolute. `/s/{slug}/` — with the slash — is a 404, and the bare
     * site root only redirects to the home page.
     */
    public static function absoluteUrl(PhpRenderer $view, SiteRepresentation $site): string
    {
        return self::url($site)
            ?? rtrim((string) $view->url('site', ['site-slug' => $site->slug()], ['force_canonical' => true]), '/');
    }
}
