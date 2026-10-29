<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Controller\SitemapController;
use DRESeo\Service\Controller\SitemapControllerFactory;
use DRESeo\Service\DefaultSite;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\Segment;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\Router\RouteMatch;
use Laminas\Uri\Http as HttpUri;
use Omeka\Api\Manager;
use Omeka\Api\Response as ApiResponse;

/**
 * The sitemap endpoints dispatched through Laminas' real controller stack,
 * over the fixture database: the headers a crawler gets, and what it gets
 * when the sitemap cannot be built.
 */
final class SitemapControllerTest extends OmekaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The default site is site 1, slug `amira`.
        $api = $this->createStub(Manager::class);
        $api->method('read')->willReturn(new ApiResponse(new FixedSite()));
        $this->services->setAllowOverride(true);
        $this->services->setService(DefaultSite::class, new DefaultSite($api, $this->settings));
        $this->settings->set('default_site', 1);
        $this->settings->set('dre_seo_sitemap_ttl', '3600');
    }

    private function dispatch(string $action): Response
    {
        $router = new TreeRouteStack();
        $router->addRoute('site', Segment::factory(['route' => '/s/:site-slug']));
        $router->setRequestUri(new HttpUri('https://example.test/'));
        $event = new MvcEvent();
        $event->setRouter($router);
        $event->setRouteMatch(new RouteMatch(['controller' => SitemapController::class, 'action' => $action]));

        $controller = (new SitemapControllerFactory())($this->services, SitemapController::class);
        $controller->setPluginManager(new PluginManager($this->services));
        $controller->setEvent($event);
        $response = $controller->dispatch(new Request(), new Response());
        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }

    public function testASitemapCarriesTheTimeItWasGeneratedEvenFromTheCache(): void
    {
        $built = $this->dispatch('index');
        $this->assertSame(200, $built->getStatusCode());
        $this->assertStringContainsString('<sitemapindex', $built->getContent());
        $this->assertSame('application/xml; charset=utf-8', $built->getHeaders()->get('Content-Type')->getFieldValue());
        $this->assertSame('noindex', $built->getHeaders()->get('X-Robots-Tag')->getFieldValue());

        // Served from the cache an hour's half later, it is still the
        // document generated then, not one generated now.
        $files = glob($this->config['file_store']['local']['base_path'] . '/dre-seo-cache/*.xml') ?: [];
        $this->assertCount(1, $files);
        $generated = time() - 1800;
        touch($files[0], $generated);

        $served = $this->dispatch('index');
        $this->assertSame($built->getContent(), $served->getContent());
        $this->assertSame(
            gmdate('D, d M Y H:i:s', $generated) . ' GMT',
            $served->getHeaders()->get('Last-Modified')->getFieldValue()
        );
    }

    /**
     * The database fails and nothing usable is cached: a 503 asking the
     * crawler to come back, never a sitemap that lists nothing.
     */
    public function testASitemapThatCannotBeBuiltIsAnsweredWithA503(): void
    {
        $this->connection->executeStatement('DROP TABLE site_page');

        $response = $this->dispatch('pages');

        $this->assertSame(503, $response->getStatusCode());
        // Laminas reads the header back as a number of seconds.
        $this->assertSame('300', (string) $response->getHeaders()->get('Retry-After')->getFieldValue());
        $this->assertStringNotContainsString('<urlset', $response->getContent());
        $this->assertSame([], glob($this->config['file_store']['local']['base_path'] . '/dre-seo-cache/*.xml') ?: []);
        $messages = array_column($this->logWriter->events, 'message');
        $this->assertContains('DRESeo: failed to fetch public site pages for sitemap generation.', $messages);
        $this->assertContains('DRESeo: the sitemap is unavailable; answered 503.', $messages);
    }

    public function testRobotsTxtIsUntouched(): void
    {
        $response = $this->dispatch('robots');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            "User-agent: *\nDisallow: /admin/\nDisallow: /login\nDisallow: /logout\nDisallow: /maintenance\n\nSitemap: https://example.test/sitemap.xml\n",
            $response->getContent()
        );
    }
}
