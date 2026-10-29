<?php
declare(strict_types=1);

namespace DRESeo\Controller;

use DRESeo\Controller\Concern\SendsResponses;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\IndexNowKey;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\RobotsTxt;
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
    use SendsResponses;

    public function __construct(
        private readonly SitemapGenerator $generator,
        private readonly SettingsGate $settings,
        private readonly DefaultSite $defaultSite,
        private readonly ?PageSeoStore $pageSeoStore = null,
        private readonly ?ProjectResolver $projects = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly RobotsTxt $robots = new RobotsTxt(),
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
            return $this->respond('Temporarily unavailable', 'text/plain; charset=utf-8', 503, [
                'Retry-After' => '300',
                'Cache-Control' => 'no-store',
            ], false);
        }
    }

    public function indexAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->respondWithStatus(404);
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->respondWithStatus(404);
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
            return $this->respondWithStatus(404);
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->respondWithStatus(404);
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
            return $this->respondWithStatus(404);
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->respondWithStatus(404);
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
            return $this->respondWithStatus(404);
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->respondWithStatus(404);
        }
        $chunk = (int) $this->params()->fromRoute('chunk', 1);
        if ($chunk < 1 || $chunk > $this->generator->itemChunkCount($site->id())) {
            return $this->respondWithStatus(404);
        }
        return $this->xml($this->generator->buildItems($this->siteUrl($site), $site->id(), $chunk, $this->ttl()));
    }

    public function robotsAction(): Response
    {
        // The staging switch closes the whole site, and then names no
        // sitemap ({@see sitemapEnabled()}).
        $site = $this->resolveSite();
        return $this->text($this->robots->render(
            $this->sitemapEnabled() && $site ? $this->hostUrl($site) . '/sitemap.xml' : null,
            $this->settings->isOn('dre_seo_noindex_site')
        ));
    }

    public function indexNowKeyAction(): Response
    {
        $configured = $this->settings->text('dre_seo_indexnow_key');
        $requested = (string) $this->params()->fromRoute('key', '');
        if (!IndexNowKey::isValid($configured) || !hash_equals($configured, $requested)) {
            return $this->respondWithStatus(404);
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
     * A sitemap document. It carries its own generation time, so
     * Last-Modified is honest whether the cache served it or it was built
     * just now.
     */
    private function xml(SitemapDocument $document): Response
    {
        // noindex: the sitemap file itself is no page to index.
        return $this->respond($document->xml, 'application/xml; charset=utf-8', 200, [
            'Last-Modified' => $document->lastModifiedHeader(),
        ]);
    }

    /** robots.txt and the IndexNow key file: plain text, sent without noindex as ever. */
    private function text(string $body): Response
    {
        return $this->respond($body, 'text/plain; charset=utf-8', 200, [], false);
    }
}
