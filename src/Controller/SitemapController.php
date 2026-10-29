<?php
declare(strict_types=1);

namespace DRESeo\Controller;

use DRESeo\Service\DefaultSite;
use DRESeo\Service\IndexNowKey;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\SettingsGate;
use DRESeo\Service\SiteHome;
use DRESeo\Service\Sitemap\SitemapDocument;
use DRESeo\Service\SitemapGenerator;
use Laminas\Http\Response;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Representation\SiteRepresentation;

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
 *
 * A sitemap that cannot be built — the database is down and no recent copy
 * is cached, or another request is still rebuilding it — is answered 503 with
 * a Retry-After, which tells a crawler to come back rather than that the
 * sitemap is gone or empty.
 */
class SitemapController extends AbstractActionController
{
    public function __construct(
        private readonly SitemapGenerator $generator,
        private readonly SettingsGate $settings,
        private readonly DefaultSite $defaultSite,
        private readonly ?PageSeoStore $pageSeoStore = null,
        private readonly ?ProjectResolver $projects = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onDispatch(MvcEvent $e): mixed
    {
        try {
            return parent::onDispatch($e);
        } catch (\Throwable $error) {
            $this->logger?->err('DRESeo: the sitemap is unavailable; answered 503.', [
                'exception' => $error,
            ]);
            $response = $this->getResponse();
            $response->setStatusCode(503);
            $response->setContent('Temporarily unavailable');
            $headers = $response->getHeaders();
            $headers->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
            $headers->addHeaderLine('Retry-After', '300');
            $headers->addHeaderLine('Cache-Control', 'no-store');
            return $response;
        }
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
        return $this->xml($this->generator->buildIndex(
            $this->hostUrl($site),
            $site->id(),
            $this->ttl(),
            $this->projectSetsRedirect($site)
        ));
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

        // Pass the site navigation tree + home page so the sitemap mirrors the
        // menu structure (order + depth-based priority) and lists the home
        // first. Guarded so a missing method or empty nav simply degrades to
        // listing every public page.
        $navTree = [];
        try {
            $nav = $site->navigation();
            $navTree = is_array($nav) ? $nav : [];
        } catch (\Throwable $e) {
            // degrade gracefully
        }

        return $this->xml($this->generator->buildPages(
            $this->siteUrl($site),
            $site->id(),
            $this->ttl(),
            $navTree,
            SiteHome::pageId($site),
            $this->noindexPageIds($site)
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
        return $this->xml($this->generator->buildItemSets(
            $this->siteUrl($site),
            $site->id(),
            $this->ttl(),
            $this->projectSetsRedirect($site)
        ));
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
        if ($this->settings->isOn('dre_seo_noindex_site')) {
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
        $configured = $this->settings->text('dre_seo_indexnow_key');
        $requested = (string) $this->params()->fromRoute('key', '');
        if (!IndexNowKey::isValid($configured) || !hash_equals($configured, $requested)) {
            return $this->notFound();
        }
        return $this->text($configured . "\n");
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveSite(): ?SiteRepresentation
    {
        return $this->defaultSite->get();
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

    /**
     * Whether the site redirects a project's item-set page to the project,
     * so the sitemap lists the project item and not the redirecting page.
     */
    private function projectSetsRedirect(SiteRepresentation $site): bool
    {
        return $this->projects?->redirectsEnabled((int) $site->id()) ?? false;
    }

    private function ttl(): int
    {
        return $this->settings->int('dre_seo_sitemap_ttl', 86400);
    }

    /**
     * Sitemaps are served when switched on and the site is not kept out of
     * the index as a whole: a sitemap listing pages that all say noindex is
     * what Search Console reports as an error.
     */
    private function sitemapEnabled(): bool
    {
        return $this->settings->isOn('dre_seo_sitemap_enabled', true)
            && !$this->settings->isOn('dre_seo_noindex_site');
    }

    /**
     * The pages an editor set to noindex (Admin → SEO → Static pages).
     *
     * @return int[]
     */
    private function noindexPageIds(SiteRepresentation $site): array
    {
        if ($this->pageSeoStore === null) {
            return [];
        }
        try {
            $this->pageSeoStore->setSite($site->id());
            return $this->pageSeoStore->noindexPageIds();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The document carries its own generation time, so Last-Modified is
     * honest whether the cache served it or it was built just now.
     */
    private function xml(SitemapDocument $document): Response
    {
        $response = $this->getResponse();
        $response->setContent($document->xml);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/xml; charset=utf-8');
        $headers->addHeaderLine('X-Robots-Tag', 'noindex'); // don't index the sitemap file itself
        $headers->addHeaderLine('Last-Modified', $document->lastModifiedHeader());
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
