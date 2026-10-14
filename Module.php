<?php
declare(strict_types=1);

/**
 * DRESeo — Omeka S module.
 *
 * Search-engine optimisation for the Africa Multiple DRE site:
 *
 *   • Injects per-page <title>, meta description, Open Graph + Twitter cards,
 *     canonical links and schema.org JSON-LD into the <head> — derived
 *     automatically from each resource's metadata + resource template, set by
 *     hand for static site pages, with site-wide defaults filling the gaps.
 *   • Serves /sitemap.xml (index + per-type children) and /robots.txt.
 *   • Injects the Google Search Console verification tag from a pasted snippet.
 *   • Optionally pings IndexNow when public content changes.
 *
 * Settings-only: it owns a handful of `dre_seo_*` settings and one site setting
 * (dre_seo_pages); install/uninstall just create and drop those. No third-party
 * Composer dependencies, so there is no bundled vendor/ — Omeka autoloads the
 * DRESeo\ namespace from src/.
 */

namespace DRESeo;

use DRESeo\Service\DefaultSite;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\IndexNowKey;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\PingQueue;
use DRESeo\Service\Signposting;
use Laminas\EventManager\EventInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Header\GenericMultiHeader;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    /** Global settings owned by the module (created on install, dropped on uninstall). */
    private const SETTINGS = [
        'dre_seo_gsc_verification',
        'dre_seo_bing_verification',
        'dre_seo_default_description',
        'dre_seo_default_share_image',
        'dre_seo_twitter_site',
        'dre_seo_noindex_site',
        'dre_seo_noindex_browse',
        'dre_seo_jsonld_enabled',
        'dre_seo_citation_meta',
        'dre_seo_sitemap_enabled',
        'dre_seo_sitemap_ttl',
        'dre_seo_ping_enabled',
        'dre_seo_indexnow_key',
        'dre_seo_ping_pending',
        'dre_seo_ping_job_queued',
        'dre_seo_ping_last',
    ];

    /** Sensible defaults applied on install. */
    private const DEFAULTS = [
        'dre_seo_sitemap_enabled' => '1',
        'dre_seo_jsonld_enabled'  => '1',
        'dre_seo_citation_meta'   => '1',
        'dre_seo_noindex_browse'  => '1',
        'dre_seo_sitemap_ttl'     => '86400',
    ];

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (self::DEFAULTS as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (self::SETTINGS as $key) {
            $settings->delete($key);
        }
        // Drop the per-site static-page overrides too.
        try {
            $siteSettings = $services->get('Omeka\Settings\Site');
            $sites = $services->get('Omeka\ApiManager')->search('sites')->getContent();
            foreach ($sites as $site) {
                $siteSettings->setTargetId($site->id());
                $siteSettings->delete('dre_seo_pages');
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * ACL: open the public sitemap/robots/IndexNow and citation-download
     * endpoints to everyone (including anonymous visitors); restrict the admin
     * SEO screens to editors and above (the /admin route already enforces
     * authentication).
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');
        $acl->allow(null, [
            Controller\SitemapController::class,
            Controller\CitationController::class,
        ]);
        $acl->allow(
            [Acl::ROLE_EDITOR, Acl::ROLE_SITE_ADMIN, Acl::ROLE_GLOBAL_ADMIN],
            [Controller\Admin\SeoController::class]
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Resource show pages — derive meta + JSON-LD from the resource.
        foreach ([
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ] as $controller) {
            $sharedEventManager->attach($controller, 'view.show.after', [$this, 'handleResourceShow']);
            $sharedEventManager->attach($controller, 'view.browse.after', [$this, 'handleBrowse']);
        }

        // Static site pages — apply the editor's per-page overrides. The home
        // page is rendered by the Index controller, so listen there too (the
        // handler no-ops when no $page is in scope).
        $sharedEventManager->attach('Omeka\Controller\Site\Page', 'view.show.after', [$this, 'handlePageShow']);
        $sharedEventManager->attach('Omeka\Controller\Site\Index', 'view.show.after', [$this, 'handlePageShow']);

        // Every public page — site-wide constants + verification + gap-fill.
        $sharedEventManager->attach('*', 'view.layout', [$this, 'handleLayout']);

        // Auto-ping on public content changes (no-op unless ping is enabled).
        foreach (['Omeka\Api\Adapter\ItemAdapter', 'Omeka\Api\Adapter\SitePageAdapter'] as $adapter) {
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleContentChange']);
        }
    }

    // ─── View listeners ─────────────────────────────────────────────────────

    public function handleResourceShow(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        $resource = $view->item ?? $view->itemSet ?? $view->media ?? null;
        if (!$site || !$resource instanceof AbstractResourceEntityRepresentation) {
            return;
        }
        $headMetadata = $this->headMetadata();
        $headMetadata->applyResource($view, $resource, $site);
        $this->addLinkHeader($headMetadata->signposts());
    }

    public function handleBrowse(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        if ($site) {
            // An item set's landing page is a browse of its items; Omeka hands
            // the set to the view, and its metadata describes the page.
            $itemSet = $view->itemSet ?? null;
            $this->headMetadata()->applyBrowse(
                $view,
                $site,
                $itemSet instanceof ItemSetRepresentation ? $itemSet : null
            );
        }
    }

    public function handlePageShow(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        $page = $view->page ?? null;
        if (!$site || !$page instanceof SitePageRepresentation) {
            return;
        }

        $store = $this->getServiceLocator()->get(PageSeoStore::class);
        $store->setSite($site->id());
        $overrides = $store->get($page->id());

        $homepage = $site->homepage();
        $isHomepage = $homepage && $homepage->id() === $page->id();

        $this->headMetadata()->applyPage($view, $page, $site, $overrides, (bool) $isHomepage);
    }

    public function handleLayout(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        // Public site pages only — skip the admin layout.
        $site = $this->currentSite($view);
        if (!$site) {
            return;
        }
        $this->headMetadata()->applyGlobals($view, $site);
    }

    // ─── API listener: queue IndexNow pings ─────────────────────────────────

    public function handleContentChange(EventInterface $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        if (!Service\SettingFlag::isOn($settings, 'dre_seo_ping_enabled')) {
            return;
        }
        $indexNowKey = trim((string) $settings->get('dre_seo_indexnow_key', ''));
        if (!IndexNowKey::isValid($indexNowKey)) {
            return;
        }

        $response = $event->getParam('response');
        $resource = $response ? $response->getContent() : null;
        if (!$resource || (method_exists($resource, 'isPublic') && !$resource->isPublic())) {
            return;
        }

        $url = $this->resourcePublicUrl($resource);
        if ($url === null) {
            return;
        }

        try {
            $services->get(PingQueue::class)->enqueue($url);
        } catch (\Throwable $e) {
            // SEO bookkeeping must never make the underlying resource save fail.
            try {
                $services->get('Omeka\Logger')->err('DRESeo: failed to queue an IndexNow URL.', [
                    'url' => $url,
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // The logger itself is best-effort at this boundary.
            }
        }
    }

    // ─── Module configuration form ──────────────────────────────────────────

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $data = [];
        foreach (self::SETTINGS as $key) {
            if (in_array($key, ['dre_seo_ping_pending', 'dre_seo_ping_job_queued', 'dre_seo_ping_last'], true)) {
                continue; // internal bookkeeping, not user-facing
            }
            $data[$key] = $settings->get($key, self::DEFAULTS[$key] ?? '');
        }
        $form->setData($data);

        return $this->omekaJsonLdWarning($renderer) . $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $data = $form->getData();
        foreach (self::SETTINGS as $key) {
            if (in_array($key, ['dre_seo_ping_pending', 'dre_seo_ping_job_queued', 'dre_seo_ping_last'], true)) {
                continue;
            }
            if (array_key_exists($key, $data)) {
                $settings->set($key, (string) $data[$key]);
            }
        }
        return true;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function headMetadata(): HeadMetadata
    {
        return $this->getServiceLocator()->get(HeadMetadata::class);
    }

    /**
     * Repeat the page's Signposting links as an HTTP `Link` header (FAIR
     * Signposting level 1), for harvesters that read headers only. A
     * multi-header, so another module's `Link` header is joined, not replaced.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    private function addLinkHeader(array $links): void
    {
        if (!$links) {
            return;
        }
        try {
            $response = $this->getServiceLocator()->get('Application')->getResponse();
            if ($response instanceof HttpResponse) {
                $response->getHeaders()->addHeader(new GenericMultiHeader('Link', Signposting::header($links)));
            }
        } catch (\Throwable $e) {
            // The head <link> elements carry the same set; the header is extra.
            $this->getServiceLocator()->get('Omeka\Logger')->warn('DRESeo: could not add the Signposting Link header.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Omeka embeds its own JSON-LD in every public resource page: the API
     * representation, under another identity, with every value of the record
     * (a thesis's full text included). It duplicates and contradicts the
     * schema.org block, and is most of the page weight. Public pages read the
     * site's setting, not the global one.
     */
    private function omekaJsonLdWarning(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        if (!Service\SettingFlag::isOn($services->get('Omeka\Settings'), 'dre_seo_jsonld_enabled', true)) {
            return '';
        }
        try {
            $site = $services->get(DefaultSite::class)->get();
            if ($site === null) {
                return '';
            }
            $siteSettings = $services->get('Omeka\Settings\Site');
            $siteSettings->setTargetId($site->id());
            // The truthiness test Omeka itself applies to this setting.
            if ($siteSettings->get('disable_jsonld_embed')) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        $message = sprintf(
            $renderer->translate('Omeka still embeds its own JSON-LD in every record page of “%s”. It describes the record under a different identity (the API URL), includes every value (a thesis’s full text too), and contradicts the schema.org block this module emits. Turn it off under Sites → %s → Settings → “Disable JSON-LD embed”.'), // @translate
            $site->title(),
            $site->title()
        );
        return '<ul class="messages"><li class="warning">' . $renderer->escapeHtml($message) . '</li></ul>';
    }

    private function view(EventInterface $event): ?PhpRenderer
    {
        $view = $event->getParam('view');
        if (!$view instanceof PhpRenderer) {
            $target = $event->getTarget();
            $view = $target instanceof PhpRenderer ? $target : null;
        }
        return $view;
    }

    private function currentSite(PhpRenderer $view): ?SiteRepresentation
    {
        $helpers = $view->getHelperPluginManager();
        if (!$helpers->has('currentSite')) {
            return null;
        }
        try {
            $site = $view->currentSite();
            return $site instanceof SiteRepresentation ? $site : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resourcePublicUrl(object $resource): ?string
    {
        $site = $this->getServiceLocator()->get(DefaultSite::class)->get();
        if ($site === null || !method_exists($resource, 'siteUrl')) {
            return null;
        }
        try {
            return $resource->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
