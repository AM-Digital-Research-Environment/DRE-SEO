<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Module;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\PingQueue;
use DRESeo\Service\SettingsGate;
use DRESeo\Test\Double\InMemoryPingOutbox;
use DRESeo\Test\Integration\Double\FixedSite;
use DRESeo\Test\Integration\Double\InMemorySettings;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\SitePageAdapter;
use Omeka\Api\Manager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Item;
use Omeka\Entity\SitePage;
use Omeka\Job\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow listeners, driven through Omeka's real event objects in the
 * order its API manager fires them. The api.*.post events carry the Doctrine
 * entity, not its representation (Manager::finalize() converts the response
 * only after they run), and an entity has no siteUrl(), so the URL is built
 * from the adapter's representation: on the default site for an item or item
 * set, and for a page only when the page belongs to the default site.
 */
final class ContentChangePingTest extends TestCase
{
    private const ITEM_ADAPTER = 'Omeka\Api\Adapter\ItemAdapter';
    private const PAGE_ADAPTER = 'Omeka\Api\Adapter\SitePageAdapter';

    private InMemoryPingOutbox $outbox;

    private InMemorySettings $settings;

    private SharedEventManager $sharedEvents;

    protected function setUp(): void
    {
        $this->outbox = new InMemoryPingOutbox();
        $this->settings = new InMemorySettings([
            'default_site' => 1,
            'dre_seo_ping_enabled' => '1',
            'dre_seo_indexnow_key' => 'a1b2c3d4e5f60718',
            // Invalidation is SitemapInvalidationTest's.
            'dre_seo_sitemap_enabled' => '0',
        ]);
        $logger = new Logger();
        $logger->addWriter(new Noop());

        // The default site is site 1, slug `amira`.
        $api = $this->createStub(Manager::class);
        $api->method('read')->willReturn(new Response(new FixedSite()));

        $services = new ServiceManager();
        $services->setService('Omeka\\Settings', $this->settings);
        $services->setService(DefaultSite::class, new DefaultSite($api, $this->settings));
        $services->setService(SettingsGate::class, new SettingsGate($this->settings));
        $services->setService(PingQueue::class, new PingQueue(
            new SettingsGate($this->settings),
            $this->createStub(Dispatcher::class),
            $this->outbox,
            $logger
        ));
        $services->setService('Omeka\\Logger', $logger);

        $module = new Module();
        $module->setServiceLocator($services);
        $this->sharedEvents = new SharedEventManager();
        $module->attachListeners($this->sharedEvents);
    }

    public function testCreatingAPublicItemQueuesItsPageOnTheDefaultSite(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame(['https://example.test/s/amira/item/42'], $this->outbox->pushed);
    }

    public function testCreatingAPrivateItemQueuesNothing(): void
    {
        $item = $this->item(false);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testAnUpdateThatMakesAnItemPrivateQueuesItSoEnginesDropIt(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);
        $request = $this->request(Request::UPDATE);

        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $adapter, $request, entity: $item);
        $item->setIsPublic(false);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $adapter, $request, $item);

        self::assertSame(['https://example.test/s/amira/item/42'], $this->outbox->pushed);
    }

    public function testAnUpdateToAnItemThatStaysPrivateQueuesNothing(): void
    {
        $item = $this->item(false);
        $adapter = $this->itemAdapter($item);
        $request = $this->request(Request::UPDATE);

        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $adapter, $request, entity: $item);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $adapter, $request, $item);

        self::assertSame([], $this->outbox->pushed);
    }

    /**
     * What an update's pre-event noted belongs to that request: two updates
     * in flight at once (a batch) do not read each other's visibility.
     */
    public function testEachUpdateKeepsItsOwnEarlierVisibility(): void
    {
        $unpublished = $this->item(true);
        $stillPrivate = $this->item(false);
        $first = $this->request(Request::UPDATE);
        $second = $this->request(Request::UPDATE);

        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $this->itemAdapter($unpublished), $first, entity: $unpublished);
        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $this->itemAdapter($stillPrivate, id: 43), $second, entity: $stillPrivate);
        $unpublished->setIsPublic(false);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $this->itemAdapter($stillPrivate, id: 43), $second, $stillPrivate);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $this->itemAdapter($unpublished), $first, $unpublished);

        self::assertSame(['https://example.test/s/amira/item/42'], $this->outbox->pushed);
    }

    public function testADeletedItemIsQueuedAtTheUrlItHadBeforeTheDeletion(): void
    {
        $item = $this->item(true);
        $deleted = false;
        $adapter = $this->itemAdapter($item, $deleted);
        $request = $this->request(Request::DELETE);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $request);
        self::assertSame([], $this->outbox->pushed, 'nothing is queued before the delete succeeds');
        // Doctrine has removed the row and cleared the id: no URL can be built now.
        $deleted = true;
        $this->fire(self::ITEM_ADAPTER, 'api.delete.post', $adapter, $request, $item);

        self::assertSame(['https://example.test/s/amira/item/42'], $this->outbox->pushed);
    }

    public function testADeletionThatNeverCompletesQueuesNothing(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $this->request(Request::DELETE));

        self::assertSame([], $this->outbox->pushed);
    }

    /** A private record's page was never public, so its deletion changes nothing a crawler saw. */
    public function testDeletingAPrivateItemQueuesNothing(): void
    {
        $item = $this->item(false);
        $adapter = $this->itemAdapter($item);
        $request = $this->request(Request::DELETE);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $request);
        $this->fire(self::ITEM_ADAPTER, 'api.delete.post', $adapter, $request, $item);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testAPageOfTheDefaultSiteIsQueuedAtItsPageUrl(): void
    {
        $page = $this->page(true);
        $adapter = $this->pageAdapter($page, 1);

        $this->fire(self::PAGE_ADAPTER, 'api.update.post', $adapter, $this->request(Request::UPDATE), $page);

        self::assertSame(['https://example.test/s/amira/page/about'], $this->outbox->pushed);
    }

    /** A page exists only on its own site, and the DRE publishes one site. */
    public function testAPageOfAnotherSiteQueuesNothing(): void
    {
        $page = $this->page(true);
        $adapter = $this->pageAdapter($page, 2);

        $this->fire(self::PAGE_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $page);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testNothingIsReadOrQueuedWhilePingingIsOff(): void
    {
        $this->settings->set('dre_seo_ping_enabled', '0');
        $item = $this->item(true);
        $adapter = $this->createMock(ItemAdapter::class);
        $adapter->expects(self::never())->method('findEntity');
        $adapter->expects(self::never())->method('getRepresentation');
        $request = $this->request(Request::DELETE);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $request);
        $this->fire(self::ITEM_ADAPTER, 'api.delete.post', $adapter, $request, $item);
        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame([], $this->outbox->pushed);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** Trigger $name as Omeka's API does: the adapter as target, the request and response as params. */
    private function fire(
        string $identifier,
        string $name,
        object $adapter,
        Request $request,
        ?object $content = null,
        ?object $entity = null
    ): void {
        $params = ['request' => $request];
        if ($content !== null) {
            $params['response'] = new Response($content);
        }
        if ($entity !== null) {
            $params['entity'] = $entity;
        }
        (new EventManager($this->sharedEvents, [$identifier]))->triggerEvent(new Event($name, $adapter, $params));
    }

    private function request(string $operation): Request
    {
        $request = new Request($operation, 'items');
        if ($operation === Request::DELETE || $operation === Request::UPDATE) {
            $request->setId(42);
        }
        return $request;
    }

    private function item(bool $public): Item
    {
        $item = new Item();
        $item->setIsPublic($public);
        return $item;
    }

    private function page(bool $public): SitePage
    {
        $page = new SitePage();
        $page->setIsPublic($public);
        return $page;
    }

    /**
     * An item adapter whose representation builds the record's canonical URL
     * on whatever site slug it is asked for — until $deleted turns true.
     */
    private function itemAdapter(Item $item, bool &$deleted = false, int $id = 42): ItemAdapter
    {
        $representation = $this->createStub(ItemRepresentation::class);
        $representation->method('siteUrl')->willReturnCallback(
            static function ($slug = null, $canonical = false) use (&$deleted, $id): string {
                if ($deleted) {
                    throw new \RuntimeException('The deleted entity has no id to route.');
                }
                return ($canonical ? 'https://example.test' : '') . '/s/' . $slug . '/item/' . $id;
            }
        );
        $adapter = $this->createStub(ItemAdapter::class);
        $adapter->method('findEntity')->willReturn($item);
        $adapter->method('getRepresentation')->willReturn($representation);
        return $adapter;
    }

    /** A page adapter whose representation belongs to site $siteId. */
    private function pageAdapter(SitePage $page, int $siteId): SitePageAdapter
    {
        $site = $this->createStub(SiteRepresentation::class);
        $site->method('id')->willReturn($siteId);
        $site->method('slug')->willReturn($siteId === 1 ? 'amira' : 'other');
        $representation = $this->createStub(SitePageRepresentation::class);
        $representation->method('site')->willReturn($site);
        $representation->method('siteUrl')->willReturnCallback(
            static fn ($slug = null, $canonical = false): string
                => ($canonical ? 'https://example.test' : '') . '/s/' . $slug . '/page/about'
        );
        $adapter = $this->createStub(SitePageAdapter::class);
        $adapter->method('findEntity')->willReturn($page);
        $adapter->method('getRepresentation')->willReturn($representation);
        return $adapter;
    }
}
