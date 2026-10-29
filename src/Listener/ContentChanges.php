<?php
declare(strict_types=1);

namespace DRESeo\Listener;

use DRESeo\Service\DefaultSite;
use DRESeo\Service\PingQueue;
use DRESeo\Service\SettingsGate;
use DRESeo\Service\SitemapGenerator;
use Laminas\EventManager\EventInterface;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Request as ApiRequest;
use Psr\Container\ContainerInterface;

/**
 * What a save does to the module's view of the site: the sitemap cache is
 * cleared, and a public item, item set or default-site page that is created,
 * edited, made private or deleted is queued for IndexNow.
 *
 * The API listeners of the Module delegate here. The pre-events note what the
 * post-events can no longer read: the visibility a record had before an
 * update, and the URL of a record being deleted. Those notes are the only
 * state, held in maps keyed by the API request object, so an entry lives
 * exactly as long as its request (batch operations finalize each sub-request
 * separately). The services are fetched when an event needs them, so a
 * request that saves nothing builds none of them, and nothing here may make
 * the save fail.
 */
final class ContentChanges
{
    /**
     * Whether the record an update changes was public before.
     *
     * @var \WeakMap<ApiRequest,bool>
     */
    private \WeakMap $wasPublic;

    /**
     * The URL of a record a delete removes.
     *
     * @var \WeakMap<ApiRequest,string>
     */
    private \WeakMap $deletedUrls;

    public function __construct(private readonly ContainerInterface $services)
    {
        $this->wasPublic = new \WeakMap();
        $this->deletedUrls = new \WeakMap();
    }

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
            || !$this->services->get(PingQueue::class)->isEnabled()
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

        if (!$this->services->get(PingQueue::class)->isEnabled()) {
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
        try {
            if (!$this->services->get(SettingsGate::class)->isOn('dre_seo_sitemap_enabled', true)) {
                return;
            }
            $this->services->get(SitemapGenerator::class)->clearCache();
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
        try {
            $queue = $this->services->get(PingQueue::class);
            $queue->push($url);
            $queue->dispatchIfDue();
        } catch (\Throwable $e) {
            // SEO bookkeeping must never make the underlying resource save fail.
            try {
                $this->services->get('Omeka\Logger')->err('DRESeo: failed to queue an IndexNow URL.', [
                    'url' => $url,
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // The logger itself is best-effort at this boundary.
            }
        }
    }

    /**
     * The URL a record is pinged at: its canonical page on the default site.
     * A site page is pinged only when it belongs to the default site, at its
     * own page URL — the home page included, which is canonical there too.
     */
    private function resourcePublicUrl(object $resource): ?string
    {
        $site = $this->services->get(DefaultSite::class)->get();
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
