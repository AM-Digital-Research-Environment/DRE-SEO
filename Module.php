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
 *   • Places every record in the site — the breadcrumb the theme shows and the
 *     BreadcrumbList state — and 301-redirects a research project's item-set
 *     page to the project item, the project's one public page.
 *
 * It owns a handful of `dre_seo_*` settings, three site settings
 * (dre_seo_pages, dre_seo_landing_pages, dre_seo_project_redirect), the
 * IndexNow outbox table `dre_seo_ping` and the sitemap cache in
 * files/dre-seo-cache; install, upgrade and uninstall create, migrate and drop
 * those. No third-party Composer dependencies, so there is no bundled vendor/
 * — Omeka autoloads the DRESeo\ namespace from src/ (and, while the module is
 * inactive, loadLifecycleDependencies() does).
 */

namespace DRESeo;

use DRESeo\Service\DefaultSite;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\LandingPages;
use DRESeo\Service\PingQueue;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\Signposting;
use DRESeo\Service\SitemapGenerator;
use Laminas\EventManager\EventInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Header\GenericMultiHeader;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Request as ApiRequest;
use Omeka\Form\SiteSettingsForm;
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
        'dre_seo_access_statement',
        'dre_seo_citation_meta',
        'dre_seo_sitemap_enabled',
        'dre_seo_sitemap_ttl',
        'dre_seo_ping_enabled',
        'dre_seo_indexnow_key',
        'dre_seo_ping_last',
    ];

    /** Internal bookkeeping settings — never surfaced in the config form. */
    private const INTERNAL_SETTINGS = [
        'dre_seo_ping_last', // when a save last dispatched the IndexNow job
    ];

    /**
     * The IndexNow queue of 0.15 and earlier, two settings rows that the
     * outbox table replaces. upgrade() moves the URLs into the table and
     * deletes both; uninstall deletes them too, for a site where they were
     * never migrated.
     */
    private const LEGACY_QUEUE_SETTINGS = [
        'dre_seo_ping_pending',
        'dre_seo_ping_job_queued',
    ];

    /** Sensible defaults applied on install. */
    private const DEFAULTS = [
        'dre_seo_sitemap_enabled' => '1',
        'dre_seo_jsonld_enabled'  => '1',
        'dre_seo_citation_meta'   => '1',
        'dre_seo_noindex_browse'  => '1',
        'dre_seo_sitemap_ttl'     => '86400',
    ];

    /**
     * What an API request's pre-events noted for its post-event, which can no
     * longer read it: whether the record an update changes was public before,
     * and the URL of a record a delete removes. Keyed by the request object, so
     * an entry lives exactly as long as its request (batch operations finalize
     * each sub-request separately).
     *
     * @var \WeakMap<ApiRequest,bool>
     */
    private \WeakMap $wasPublic;

    /** @var \WeakMap<ApiRequest,string> */
    private \WeakMap $deletedUrls;

    public function __construct()
    {
        $this->wasPublic = new \WeakMap();
        $this->deletedUrls = new \WeakMap();
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->loadLifecycleDependencies();
        $this->applyDefaults($services->get('Omeka\Settings'));
        (new Service\PingRepository($services->get('Omeka\Connection')))->install();
    }

    /**
     * Re-apply any default that is still unset.
     *
     * Omeka calls this when the version in module.ini is newer than the one it
     * recorded. Without it a default introduced after a site's first install
     * would never reach that site: install() runs once, and nothing else
     * writes a default. Idempotent, and it never overwrites a value an
     * administrator has set.
     *
     * It also creates the IndexNow outbox table, which 0.15 and earlier did
     * not have, and moves the URLs their settings-backed queue still held into
     * it. Every step is safe to repeat, so a retried upgrade neither fails
     * nor queues a URL twice.
     *
     * @param string $oldVersion
     * @param string $newVersion
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        $this->loadLifecycleDependencies();
        $settings = $services->get('Omeka\Settings');
        $this->applyDefaults($settings);

        $outbox = new Service\PingRepository($services->get('Omeka\Connection'));
        $outbox->install();
        foreach ((array) $settings->get('dre_seo_ping_pending', []) as $url) {
            if (is_string($url)) {
                $outbox->push(trim($url));
            }
        }
        foreach (self::LEGACY_QUEUE_SETTINGS as $key) {
            $settings->delete($key);
        }
    }

    /**
     * Make the module's own classes loadable during install, upgrade and
     * uninstall.
     *
     * Omeka runs all three while the module may be inactive — always for
     * install and upgrade, and for an uninstall after a deactivation — and an
     * inactive module's src/ is not autoloaded: AbstractModule registers that
     * loader only for the modules Laminas loads. A PSR-4 loader scoped to
     * DRESeo\ resolves whatever the lifecycle touches, so a class it comes to
     * need cannot fall off a hand-kept list. (IWAC SEO 1.1.0 shipped exactly
     * that failure: an upgrade that stopped at "Class not found".)
     */
    private function loadLifecycleDependencies(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $src = __DIR__ . '/src/';
        spl_autoload_register(static function (string $class) use ($src): void {
            if (!str_starts_with($class, __NAMESPACE__ . '\\')) {
                return;
            }
            $relative = substr($class, strlen(__NAMESPACE__) + 1);
            $file = $src . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }, true, true);
    }

    /** Set each default that has no stored value yet. */
    private function applyDefaults(\Omeka\Settings\Settings $settings): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        // The outbox and the site-setting keys below are classes of src/.
        $this->loadLifecycleDependencies();
        (new Service\PingRepository($services->get('Omeka\Connection')))->uninstall();
        $settings = $services->get('Omeka\Settings');
        foreach ([...self::SETTINGS, ...self::LEGACY_QUEUE_SETTINGS] as $key) {
            $settings->delete($key);
        }
        // Remove the sitemap cache (files/dre-seo-cache). The generator is
        // built from its factory: an inactive module's services are not
        // registered, while the Omeka services the factory reads always are.
        try {
            (new Service\SitemapGeneratorFactory())($services, Service\SitemapGenerator::class)->destroyCache();
        } catch (\Throwable $e) {
            // best-effort
        }
        // Drop the site settings too: the static-page overrides, the
        // breadcrumb landing pages and the project redirect switch.
        try {
            $siteSettings = $services->get('Omeka\Settings\Site');
            $sites = $services->get('Omeka\ApiManager')->search('sites')->getContent();
            foreach ($sites as $site) {
                $siteSettings->setTargetId($site->id());
                foreach (['dre_seo_pages', LandingPages::SETTING, ProjectResolver::REDIRECT_SETTING] as $key) {
                    $siteSettings->delete($key);
                }
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

        // After core's route listeners (priority 1) have read the site, and
        // before dispatch: the theme never renders a page that redirects.
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectProjectItemSet'],
            -100
        );
    }

    /**
     * A research project's item-set page (`/s/{site}/item-set/{id}`, GET or
     * HEAD) answers 301 with the project item's page in the same site, when
     * the item set stands for a public project and the site has not switched
     * the redirect off ({@see ProjectResolver}). Returning the response ends
     * the route event, so neither the controller nor the theme runs. Nothing
     * here touches the session, and any failure leaves the page as it was.
     */
    public function redirectProjectItemSet(MvcEvent $event): ?HttpResponse
    {
        try {
            $routeMatch = $event->getRouteMatch();
            $request = $event->getRequest();
            if (
                $routeMatch === null || $routeMatch->getMatchedRouteName() !== 'site/item-set'
                || !$request instanceof HttpRequest
            ) {
                return null;
            }
            // Core's preparePublicSite has put the site on the view model.
            $site = $event->getViewModel()->getVariable('site');
            if (!$site instanceof SiteRepresentation) {
                return null;
            }
            $services = $event->getApplication()->getServiceManager();
            $projects = $services->get(ProjectResolver::class);
            // The cheap checks first: no item set is read for a POST, or when
            // the site keeps its item-set pages.
            if (
                !in_array($request->getMethod(), [HttpRequest::METHOD_GET, HttpRequest::METHOD_HEAD], true)
                || !$projects->redirectsEnabled((int) $site->id())
            ) {
                return null;
            }
            try {
                // As the current user: a private item set is not found.
                $itemSet = $services->get('Omeka\ApiManager')
                    ->read('item_sets', (int) $routeMatch->getParam('item-set-id'))
                    ->getContent();
            } catch (\Throwable $e) {
                return null;
            }
            $url = $projects->itemSetRedirectUrl(
                $request->getMethod(),
                (string) $routeMatch->getMatchedRouteName(),
                $itemSet instanceof ItemSetRepresentation ? $itemSet : null,
                $site
            );
            $response = $event->getResponse();
            if ($url === null || !$response instanceof HttpResponse) {
                return null;
            }
            $response->setStatusCode(HttpResponse::STATUS_CODE_301);
            $response->getHeaders()->addHeaderLine('Location', $url);
            return $response;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Resource show pages — derive meta + JSON-LD from the resource.
        foreach (
            [
                'Omeka\Controller\Site\Item',
                'Omeka\Controller\Site\ItemSet',
                'Omeka\Controller\Site\Media',
            ] as $controller
        ) {
            $sharedEventManager->attach($controller, 'view.show.after', [$this, 'handleResourceShow']);
            $sharedEventManager->attach($controller, 'view.browse.after', [$this, 'handleBrowse']);
        }

        // Static site pages — apply the editor's per-page overrides. The home
        // page is rendered by the Index controller, so listen there too (the
        // handler no-ops when no $page is in scope).
        $sharedEventManager->attach('Omeka\Controller\Site\Page', 'view.show.after', [$this, 'handlePageShow']);
        $sharedEventManager->attach('Omeka\Controller\Site\Index', 'view.show.after', [$this, 'handlePageShow']);

        // The site's settings: the breadcrumb's landing pages and the project
        // item-set redirect.
        $sharedEventManager->attach(SiteSettingsForm::class, 'form.add_elements', [$this, 'addSiteSettings']);
        $sharedEventManager->attach(SiteSettingsForm::class, 'form.add_input_filters', [$this, 'addSiteSettingsFilters']);

        // Every public page — site-wide constants + verification + gap-fill.
        $sharedEventManager->attach('*', 'view.layout', [$this, 'handleLayout']);

        // Sitemap invalidation + auto-ping on content changes. Items, item
        // sets and pages are sitemap URLs of their own: any create, update or
        // delete can change a sitemap's URL set or a <lastmod> (and an item's
        // visibility decides whether its project's item set is listed), so it
        // clears the cache, and a public record created, edited, made private
        // or deleted is pinged (no-op unless ping is enabled). The pre-events
        // note what the post-events can no longer read: the visibility a
        // record had before an update, and the URL of a record being deleted.
        foreach (
            [
                'Omeka\Api\Adapter\ItemAdapter',
                'Omeka\Api\Adapter\ItemSetAdapter',
                'Omeka\Api\Adapter\SitePageAdapter',
            ] as $adapter
        ) {
            $sharedEventManager->attach($adapter, 'api.hydrate.pre', [$this, 'noteVisibility']);
            $sharedEventManager->attach($adapter, 'api.delete.pre', [$this, 'noteDeletion']);
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.delete.post', [$this, 'handleContentChange']);
        }

        // Changes that can alter a sitemap without being a sitemap URL of
        // their own clear the cache and queue no IndexNow ping. A site's
        // navigation and homepage order the pages sitemap, and its item pool
        // and item sets decide which records the other sitemaps list. A media
        // reaches no DRE sitemap document today (the items sitemap carries no
        // image entries, as IWAC SEO's does), but its visibility would the day
        // one does, and a clear costs one rebuild.
        foreach (['Omeka\Api\Adapter\MediaAdapter', 'Omeka\Api\Adapter\SiteAdapter'] as $adapter) {
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleSitemapChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleSitemapChange']);
            $sharedEventManager->attach($adapter, 'api.delete.post', [$this, 'handleSitemapChange']);
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
            $headMetadata = $this->headMetadata();
            $headMetadata->applyBrowse(
                $view,
                $site,
                $itemSet instanceof ItemSetRepresentation ? $itemSet : null
            );
            $this->addLinkHeader($headMetadata->signposts());
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

        $isHomepage = Service\SiteHome::pageId($site) === $page->id();

        $this->headMetadata()->applyPage($view, $page, $site, $overrides, $isHomepage);
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
        $this->headMetadata()->applyGlobals($view, $site, $this->isSearchPage());
    }

    /**
     * Whether the request is a search results page: core's site search
     * (`/s/{slug}/index/search`) and advanced-search forms (`…/item/search`),
     * or DRE Search's results page (`/s/{slug}/dre-search`).
     */
    private function isSearchPage(): bool
    {
        try {
            $routeMatch = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch();
        } catch (\Throwable $e) {
            return false;
        }
        if ($routeMatch === null) {
            return false;
        }
        return Service\SearchPage::matches(
            (string) $routeMatch->getMatchedRouteName(),
            (string) $routeMatch->getParam('controller', ''),
            (string) $routeMatch->getParam('action', '')
        );
    }

    // ─── API listener: queue IndexNow pings ─────────────────────────────────

    /**
     * Note whether a record was public before an update changes it, so an
     * edit that makes it private can be pinged too. api.hydrate.pre is the
     * last point at which the entity still holds its stored visibility.
     */
    public function noteVisibility(EventInterface $event): void
    {
        $request = $event->getParam('request');
        $entity = $event->getParam('entity');
        if (
            !$request instanceof ApiRequest
            || $request->getOperation() !== ApiRequest::UPDATE
            || !is_object($entity) || !method_exists($entity, 'isPublic')
        ) {
            return;
        }
        $this->wasPublic[$request] = (bool) $entity->isPublic();
    }

    /**
     * Note the URL of a public record about to be deleted, so engines recrawl
     * it and find it gone. By api.delete.post Doctrine has cleared the
     * entity's id, so the URL can no longer be built. The ping itself waits
     * for the post-event: a delete that fails pings nothing.
     */
    public function noteDeletion(EventInterface $event): void
    {
        $request = $event->getParam('request');
        $adapter = $event->getTarget();
        if (
            !$request instanceof ApiRequest
            || !is_object($adapter) || !method_exists($adapter, 'findEntity')
            || !$this->getServiceLocator()->get(PingQueue::class)->isEnabled()
        ) {
            return;
        }
        try {
            $entity = $adapter->findEntity($request->getId(), $request);
        } catch (\Throwable $e) {
            return;
        }
        if (!is_object($entity) || (method_exists($entity, 'isPublic') && !$entity->isPublic())) {
            return;
        }
        $url = $this->publicUrl($adapter, $entity);
        if ($url !== null) {
            $this->deletedUrls[$request] = $url;
        }
    }

    /**
     * A record a save changed: invalidate the sitemap, then queue the record
     * for IndexNow — a created or updated one when it is public, so engines
     * crawl the change, or when it has just been made private, so they
     * recrawl the page and drop it; a deleted one at the URL noted before the
     * deletion.
     */
    public function handleContentChange(EventInterface $event): void
    {
        // Read what the request's pre-events noted, whether or not a ping follows.
        $request = $event->getParam('request');
        $wasPublic = false;
        $deletedUrl = null;
        if ($request instanceof ApiRequest) {
            $wasPublic = $this->wasPublic[$request] ?? false;
            $deletedUrl = $this->deletedUrls[$request] ?? null;
            unset($this->wasPublic[$request], $this->deletedUrls[$request]);
        }

        $this->invalidateSitemap();

        if (!$this->getServiceLocator()->get(PingQueue::class)->isEnabled()) {
            return;
        }
        if ((string) $event->getName() === 'api.delete.post') {
            $url = $deletedUrl;
        } else {
            $response = $event->getParam('response');
            $entity = is_object($response) && method_exists($response, 'getContent') ? $response->getContent() : null;
            if (!is_object($entity)) {
                return;
            }
            $isPublic = !method_exists($entity, 'isPublic') || $entity->isPublic();
            $url = $isPublic || $wasPublic ? $this->publicUrl($event->getTarget(), $entity) : null;
        }
        if ($url !== null) {
            $this->queuePing($url);
        }
    }

    /** A change that reshapes a sitemap without being one of its URLs: invalidate only. */
    public function handleSitemapChange(EventInterface $event): void
    {
        $this->invalidateSitemap();
    }

    /**
     * Invalidate the sitemap cache so a change shows on the next crawl
     * instead of after the cache lifetime (24 hours by default) or the
     * dashboard's Regenerate button. Any create, update or delete can affect
     * the URL set or a <lastmod>, a public→private edit included, so there is
     * no public check. A failure is the generator's to log; it must never
     * break the save.
     */
    private function invalidateSitemap(): void
    {
        $services = $this->getServiceLocator();
        try {
            if (!Service\SettingFlag::isOn($services->get('Omeka\Settings'), 'dre_seo_sitemap_enabled', true)) {
                return;
            }
            $services->get(SitemapGenerator::class)->clearCache();
        } catch (\Throwable $e) {
            // never let SEO bookkeeping break a save
        }
    }

    /** The URL an entity is pinged at, through its API representation. */
    private function publicUrl(mixed $adapter, object $entity): ?string
    {
        $resource = $this->representation($adapter, $entity);
        return $resource ? $this->resourcePublicUrl($resource) : null;
    }

    /**
     * The API representation of an entity. The api.*.post events carry the
     * entity itself — the response is turned into a representation only
     * after they run — so it is built from the adapter.
     */
    private function representation(mixed $adapter, object $entity): ?object
    {
        if ($entity instanceof AbstractResourceEntityRepresentation || $entity instanceof SitePageRepresentation) {
            return $entity;
        }
        if (!is_object($adapter) || !method_exists($adapter, 'getRepresentation')) {
            return null;
        }
        try {
            return $adapter->getRepresentation($entity);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Put a URL in the outbox, then start a drain job if none was started in
     * the last quarter hour; the cron drains it in any case.
     */
    private function queuePing(string $url): void
    {
        $services = $this->getServiceLocator();
        try {
            $queue = $services->get(PingQueue::class);
            $queue->push($url);
            $queue->dispatchIfDue();
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

    // ─── Site settings (Sites → {site} → Settings → SEO) ────────────────────

    public function addSiteSettings(EventInterface $event): void
    {
        $form = $event->getTarget();
        if (!$form instanceof SiteSettingsForm) {
            return;
        }
        $services = $this->getServiceLocator();
        // Core targets the site settings at the site being edited.
        $siteSettings = $services->get('Omeka\Settings\Site');
        $defaults = (array) ($services->get('Config')['dre_seo']['breadcrumb']['landing_pages'] ?? []);

        $groups = (array) ($form->getOption('element_groups') ?? []);
        $groups['dre_seo'] = 'SEO'; // @translate
        $form->setOption('element_groups', $groups);

        $redirect = $siteSettings->get(ProjectResolver::REDIRECT_SETTING);
        $form->add([
            'name'    => ProjectResolver::REDIRECT_SETTING,
            'type'    => 'checkbox',
            'options' => [
                'element_group' => 'dre_seo',
                'label' => 'Redirect a project’s item set page to the project', // @translate
                'info'  => 'A research project is both an item and the item set of its collection. When on, the item set’s page answers with a permanent redirect (301) to the project item, which is then the project’s one page in search engines and the sitemap. An item set is a project’s when its “Is Part Of” links to the public project item, or the public project item has the same dre:id.', // @translate
            ],
            'attributes' => [
                'id'    => ProjectResolver::REDIRECT_SETTING,
                // On unless switched off.
                'value' => in_array($redirect, [null, '', '1', 1, true], true) ? '1' : '0',
            ],
        ]);
        $form->add([
            'name'    => LandingPages::SETTING,
            'type'    => 'textarea',
            'options' => [
                'element_group' => 'dre_seo',
                'label' => 'Breadcrumb landing pages', // @translate
                'info'  => 'The page that presents each item set’s records, named in the breadcrumb between the home page and the record: one “item set id = page slug” per line. A line replaces the module’s default for that item set, and “id =” with no slug removes it. A page that does not exist or is private is skipped. Blank: the defaults shown.', // @translate
            ],
            'attributes' => [
                'id'          => LandingPages::SETTING,
                'rows'        => 8,
                'placeholder' => LandingPages::format($defaults),
                'value'       => (string) ($siteSettings->get(LandingPages::SETTING) ?? ''),
            ],
        ]);
    }

    public function addSiteSettingsFilters(EventInterface $event): void
    {
        $inputFilter = $event->getParam('inputFilter');
        if (!is_object($inputFilter) || !method_exists($inputFilter, 'add')) {
            return;
        }
        $inputFilter->add(['name' => ProjectResolver::REDIRECT_SETTING, 'required' => false]);
        $inputFilter->add([
            'name'       => LandingPages::SETTING,
            'required'   => false,
            'filters'    => [['name' => 'StringTrim']],
            'validators' => [[
                'name'    => 'Callback',
                'options' => [
                    'callback' => static fn ($value): bool => LandingPages::invalidLines((string) $value) === [],
                    'messages' => [
                        'callbackValue' => 'Write one “item set id = page slug” per line, for example “29918 = publications”.', // @translate
                    ],
                ],
            ]],
        ]);
    }

    // ─── Module configuration form ──────────────────────────────────────────

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $data = [];
        foreach (self::SETTINGS as $key) {
            if (in_array($key, self::INTERNAL_SETTINGS, true)) {
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
            if (in_array($key, self::INTERNAL_SETTINGS, true)) {
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

    /**
     * The URL a record is pinged at: its canonical page on the default site.
     * A site page is pinged only when it belongs to the default site, at its
     * own page URL — the home page included, which is canonical there too.
     */
    private function resourcePublicUrl(object $resource): ?string
    {
        $site = $this->getServiceLocator()->get(DefaultSite::class)->get();
        if ($site === null || !method_exists($resource, 'siteUrl')) {
            return null;
        }
        try {
            if ($resource instanceof SitePageRepresentation) {
                return $resource->site()->id() === $site->id() ? $resource->siteUrl($site->slug(), true) : null;
            }
            return $resource->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
