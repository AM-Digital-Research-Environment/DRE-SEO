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
 *
 * This file is the wiring: services, listeners, ACL, the lifecycle and the
 * configuration form. What the listeners do lives in src/ — the head in
 * Service\HeadMetadata, the API listeners in Listener\ContentChanges, the
 * project redirect in Listener\ProjectItemSetRedirect, the site settings in
 * Form\SiteSettingsElements — and the listener methods named here delegate.
 */

namespace DRESeo;

use DRESeo\Service\DefaultSite;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\LandingPages;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\Signposting;
use DRESeo\Service\SitemapGenerator;
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

    /** The API listeners' state and logic, built on the first content change. */
    private ?Listener\ContentChanges $contentChanges = null;

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
     * A research project's item-set page answers 301 with the project item
     * ({@see Listener\ProjectItemSetRedirect}).
     */
    public function redirectProjectItemSet(MvcEvent $event): ?HttpResponse
    {
        return (new Listener\ProjectItemSetRedirect())($event);
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
        $this->headMetadata()->applyResource($view, $resource, $site);
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
        $headMetadata = $this->headMetadata();
        $headMetadata->applyGlobals($view, $site, $this->isSearchPage());
        // Once per page, after the action render has said what the page is:
        // a template may trigger both the show and the browse event.
        $this->addLinkHeader($headMetadata->signposts());
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

    // ─── API listeners: sitemap invalidation, IndexNow pings ────────────────

    // Named in the wiring above. The state and the logic live in one
    // Listener\ContentChanges per request, which the pre- and post-events of
    // an API request must share.

    public function noteVisibility(EventInterface $event): void
    {
        $this->contentChanges()->noteVisibility($event);
    }

    public function noteDeletion(EventInterface $event): void
    {
        $this->contentChanges()->noteDeletion($event);
    }

    public function handleContentChange(EventInterface $event): void
    {
        $this->contentChanges()->handleContentChange($event);
    }

    public function handleSitemapChange(EventInterface $event): void
    {
        $this->contentChanges()->handleSitemapChange($event);
    }

    private function contentChanges(): Listener\ContentChanges
    {
        return $this->contentChanges ??= new Listener\ContentChanges($this->getServiceLocator());
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
        (new Form\SiteSettingsElements(
            $services->get('Omeka\Settings\Site'),
            (array) ($services->get('Config')['dre_seo']['breadcrumb']['landing_pages'] ?? [])
        ))->addTo($form);
    }

    public function addSiteSettingsFilters(EventInterface $event): void
    {
        $inputFilter = $event->getParam('inputFilter');
        if (is_object($inputFilter) && method_exists($inputFilter, 'add')) {
            Form\SiteSettingsElements::addFilters($inputFilter);
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
     * Signposting level 1), for harvesters that read headers only, within
     * {@see Signposting::HEADER_BUDGET}.
     *
     * Joined to any Link header another module set, never replacing it.
     * Laminas decides whether a header may repeat by the class registered for
     * its name, not by the object handed in, and registers none for Link: a
     * second addHeader() would overwrite the first, and a raw header line
     * would be sent as a single header that PHP lets the last one replace.
     * Registering the multi-header class for Link on this response makes every
     * Link header in it one of several, each sent without replacing the rest.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    private function addLinkHeader(array $links): void
    {
        $value = Signposting::header($links);
        if ($value === '') {
            return;
        }
        try {
            $response = $this->getServiceLocator()->get('Application')->getResponse();
            if ($response instanceof HttpResponse) {
                $headers = $response->getHeaders();
                $headers->getPluginClassLoader()->registerPlugin('link', GenericMultiHeader::class);
                $headers->addHeader(new GenericMultiHeader('Link', $value));
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
        if (!$services->get(Service\SettingsGate::class)->isOn('dre_seo_jsonld_enabled', true)) {
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
}
