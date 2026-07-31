<?php
declare(strict_types=1);

namespace DRESeo\Controller;

use DRESeo\Service\IndexNowKey;
use DRESeo\Service\SitemapGenerator;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * Public, anonymous endpoints served at the host root (the routes fall through
 * nginx to Omeka's index.php — no web-server config needed):
 *
 *   /sitemap.xml               sitemap index
 *   /sitemap-pages.xml         site pages (+ home)
 *   /sitemap-item-sets.xml     item-set browse pages
 *   /sitemap-items-{n}.xml     items, chunked at 50k
 *   /robots.txt                points crawlers at the sitemap
 *   /{indexnow-key}.txt        IndexNow ownership key
 */
class SitemapController extends AbstractActionController
{
    public function __construct(
        private readonly SitemapGenerator $generator,
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildIndex($this->hostUrl($site), $site->id(), $this->ttl()));
    }

    public function pagesAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }

        // Pass the site navigation tree + homepage so the sitemap mirrors the
        // menu structure (order + depth-based priority). Guarded so a missing
        // method or empty nav simply degrades to listing every public page.
        $navTree = [];
        $homepageId = null;
        try {
            $nav = $site->navigation();
            $navTree = is_array($nav) ? $nav : [];
            $homepage = $site->homepage();
            $homepageId = $homepage ? $homepage->id() : null;
        } catch (\Throwable $e) {
            // degrade gracefully
        }

        return $this->xml($this->generator->buildPages(
            $this->siteUrl($site),
            $this->hostUrl($site),
            $site->id(),
            $this->ttl(),
            $navTree,
            $homepageId
        ));
    }

    public function itemSetsAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildItemSets($this->siteUrl($site), $site->id(), $this->ttl()));
    }

    public function itemsAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        $chunk = (int) $this->params()->fromRoute('chunk', 1);
        if ($chunk < 1 || $chunk > $this->generator->itemChunkCount($site->id())) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildItems($this->siteUrl($site), $site->id(), $chunk, $this->ttl()));
    }

    public function robotsAction(): Response
    {
        $lines = ['User-agent: *'];
        if ($this->boolSetting('dre_seo_noindex_site')) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin/';
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /logout';
            $lines[] = 'Disallow: /maintenance';
        }

        $site = $this->resolveSite();
        if ($this->sitemapEnabled() && $site) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $this->hostUrl($site) . '/sitemap.xml';
        }

        return $this->text(implode("\n", $lines) . "\n");
    }

    public function indexNowKeyAction(): Response
    {
        $configured = trim((string) $this->settings->get('dre_seo_indexnow_key', ''));
        $requested = (string) $this->params()->fromRoute('key', '');
        if (!IndexNowKey::isValid($configured) || !hash_equals($configured, $requested)) {
            return $this->notFound();
        }
        return $this->text($configured . "\n");
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveSite(): ?SiteRepresentation
    {
        $defaultSiteId = (int) $this->settings->get('default_site');
        if ($defaultSiteId) {
            try {
                return $this->api->read('sites', $defaultSiteId)->getContent();
            } catch (\Throwable $e) {
                // fall through to first site
            }
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
            return $sites[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function siteUrl(SiteRepresentation $site): string
    {
        return rtrim(
            $this->url()->fromRoute('site', ['site-slug' => $site->slug()], ['force_canonical' => true]),
            '/'
        );
    }

    private function hostUrl(SiteRepresentation $site): string
    {
        $parts = parse_url($this->siteUrl($site));
        $host = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }

    private function ttl(): int
    {
        return (int) $this->settings->get('dre_seo_sitemap_ttl', 86400);
    }

    private function sitemapEnabled(): bool
    {
        return $this->boolSetting('dre_seo_sitemap_enabled', true);
    }

    private function boolSetting(string $key, bool $default = false): bool
    {
        $value = $this->settings->get($key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }

    private function xml(string $body): Response
    {
        $response = $this->getResponse();
        $response->setContent($body);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/xml; charset=utf-8');
        $headers->addHeaderLine('X-Robots-Tag', 'noindex'); // don't index the sitemap file itself
        return $response;
    }

    private function text(string $body): Response
    {
        $response = $this->getResponse();
        $response->setContent($body);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        return $response;
    }

    private function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);
        return $response;
    }
}
