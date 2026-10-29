<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\SitemapGenerator;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Site;
use Omeka\Entity\SitePage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A content change clears the cached sitemap, so it shows on the next crawl
 * rather than after the cache lifetime. Items, item sets and pages are
 * sitemap URLs of their own and are also pinged; media and sites only reshape
 * a sitemap, and are not.
 */
final class SitemapInvalidationTest extends OmekaTestCase
{
    /** @return array<string,array{string,string,class-string}> */
    public static function changes(): array
    {
        $changes = [];
        foreach (['create', 'update', 'delete'] as $operation) {
            $changes['item ' . $operation] = ['Omeka\\Api\\Adapter\\ItemAdapter', $operation, Item::class];
            $changes['item set ' . $operation] = ['Omeka\\Api\\Adapter\\ItemSetAdapter', $operation, ItemSet::class];
            $changes['site page ' . $operation] = ['Omeka\\Api\\Adapter\\SitePageAdapter', $operation, SitePage::class];
            $changes['media ' . $operation] = ['Omeka\\Api\\Adapter\\MediaAdapter', $operation, Media::class];
            $changes['site ' . $operation] = ['Omeka\\Api\\Adapter\\SiteAdapter', $operation, Site::class];
        }
        return $changes;
    }

    /** @param class-string $entityClass */
    #[DataProvider('changes')]
    public function testAChangeClearsTheCachedSitemap(string $adapter, string $operation, string $entityClass): void
    {
        $this->cacheTheSitemap();

        $this->fire($adapter, $operation, new $entityClass());

        $this->assertSame([], $this->cachedFiles(), sprintf('%s %s left the sitemap cached', $adapter, $operation));
    }

    public function testNothingIsClearedWhileTheSitemapIsSwitchedOff(): void
    {
        $this->cacheTheSitemap();
        $this->settings->set('dre_seo_sitemap_enabled', '0');

        $this->fire('Omeka\\Api\\Adapter\\ItemAdapter', 'update', new Item());

        $this->assertCount(1, $this->cachedFiles());
    }

    /** The listeners attach where Omeka's API triggers each type's events. */
    public function testEveryContentTypeWithASitemapInvalidatesIt(): void
    {
        $handlers = function (string $adapter, string $event): array {
            $out = [];
            foreach ($this->sharedEvents->getListeners(['Omeka\\Api\\Adapter\\' . $adapter], $event) as $byPriority) {
                foreach ($byPriority as $listener) {
                    $out[] = is_array($listener) ? $listener[1] : null;
                }
            }
            return $out;
        };
        foreach (['api.create.post', 'api.update.post', 'api.delete.post'] as $event) {
            // Sitemap URLs of their own: invalidate and ping.
            foreach (['ItemAdapter', 'ItemSetAdapter', 'SitePageAdapter'] as $adapter) {
                $this->assertSame(['handleContentChange'], $handlers($adapter, $event), $adapter . ' ' . $event);
            }
            // A site's navigation, homepage and pools reshape the sitemaps,
            // and so would a media's visibility: invalidate only.
            foreach (['MediaAdapter', 'SiteAdapter'] as $adapter) {
                $this->assertSame(['handleSitemapChange'], $handlers($adapter, $event), $adapter . ' ' . $event);
            }
        }
        // The pinged types note, before the change, what the post-event cannot read.
        foreach (['api.hydrate.pre' => 'noteVisibility', 'api.delete.pre' => 'noteDeletion'] as $event => $handler) {
            foreach (['ItemAdapter', 'ItemSetAdapter', 'SitePageAdapter'] as $adapter) {
                $this->assertSame([$handler], $handlers($adapter, $event), $adapter . ' ' . $event);
            }
            foreach (['MediaAdapter', 'SiteAdapter'] as $adapter) {
                $this->assertSame([], $handlers($adapter, $event), $adapter . ' ' . $event);
            }
        }
    }

    private function cacheTheSitemap(): void
    {
        $this->services->get(SitemapGenerator::class)->buildIndex('https://example.test', 1, 3600);
        $this->assertCount(1, $this->cachedFiles(), 'the index was not cached');
    }

    /** @return string[] */
    private function cachedFiles(): array
    {
        return glob($this->config['file_store']['local']['base_path'] . '/dre-seo-cache/*.xml') ?: [];
    }

    /** Trigger the operation's post-event as Omeka's API manager does. */
    private function fire(string $adapter, string $operation, object $entity): void
    {
        $request = new Request($operation, 'items');
        (new EventManager($this->sharedEvents, [$adapter]))->triggerEvent(new Event(
            'api.' . $operation . '.post',
            null,
            ['request' => $request, 'response' => new Response($entity)]
        ));
    }
}
