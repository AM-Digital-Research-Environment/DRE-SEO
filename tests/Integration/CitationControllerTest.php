<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Controller\CitationController;
use DRESeo\Service\CitationExport;
use DRESeo\Service\Controller\CitationControllerFactory;
use DRESeo\Service\DefaultSite;
use DRESeo\Test\Integration\Double\FixedItem;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\Router\RouteMatch;
use Laminas\Uri\Http as HttpUri;
use Laminas\View\Helper\Url;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager;
use Omeka\Api\Response as ApiResponse;

/**
 * /cite/{id}/{format} dispatched through Laminas' real controller stack: what a
 * reference manager gets for a public item, a private one and a format that
 * does not exist, and how it revalidates.
 */
final class CitationControllerTest extends OmekaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $items = [
            7 => new FixedItem(7, 'A public record', true, new \DateTime('2026-03-04 05:06:07 UTC')),
            8 => new FixedItem(8, 'A private record', false),
        ];
        $api = $this->createStub(Manager::class);
        $api->method('read')->willReturnCallback(static function (string $resource, $id) use ($items): ApiResponse {
            if ($resource === 'sites') {
                return new ApiResponse(new FixedSite());
            }
            // Omeka's API answers an unknown id, or one the visitor may not
            // see, with a NotFoundException.
            return isset($items[$id]) ? new ApiResponse($items[$id]) : throw new NotFoundException();
        });
        $this->services->setAllowOverride(true);
        $this->services->setService('Omeka\ApiManager', $api);
        $this->services->setService(DefaultSite::class, new DefaultSite($api, $this->settings));
        $this->settings->set('default_site', 1);
    }

    /** @param array<string,string> $headers */
    private function dispatch(int $id, string $format, array $headers = []): Response
    {
        $event = new MvcEvent();
        $event->setRouteMatch(new RouteMatch([
            'controller' => CitationController::class,
            'action' => 'index',
            'id' => (string) $id,
            'format' => $format,
        ]));

        $request = new Request();
        foreach ($headers as $name => $value) {
            $request->getHeaders()->addHeaderLine($name, $value);
        }
        $controller = (new CitationControllerFactory())($this->services, CitationController::class);
        $controller->setPluginManager(new PluginManager($this->services));
        $controller->setEvent($event);
        $response = $controller->dispatch($request, new Response());
        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }

    public function testAPublicItemDownloadsWithCacheValidators(): void
    {
        $response = $this->dispatch(7, 'bibtex');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('A public record', $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('application/x-bibtex; charset=utf-8', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame('*', $headers->get('Access-Control-Allow-Origin')->getFieldValue());
        $this->assertSame('noindex', $headers->get('X-Robots-Tag')->getFieldValue());
        $this->assertSame('Wed, 04 Mar 2026 05:06:07 GMT', $headers->get('Last-Modified')->getFieldValue());
        $this->assertStringStartsWith('attachment; filename="', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertMatchesRegularExpression('/^"[^"]+"$/', $headers->get('ETag')->getFieldValue());
    }

    public function testAConditionalRequestForTheCurrentCopyIsAnswered304WithoutABody(): void
    {
        $etag = $this->dispatch(7, 'ris')->getHeaders()->get('ETag')->getFieldValue();

        $revalidated = $this->dispatch(7, 'ris', ['If-None-Match' => $etag]);

        $this->assertSame(304, $revalidated->getStatusCode());
        $this->assertSame('', $revalidated->getContent());
        $this->assertSame($etag, $revalidated->getHeaders()->get('ETag')->getFieldValue());
        $this->assertSame('*', $revalidated->getHeaders()->get('Access-Control-Allow-Origin')->getFieldValue());
        $this->assertFalse($revalidated->getHeaders()->has('Content-Disposition'), 'a 304 has no body to name');

        $stale = $this->dispatch(7, 'ris', ['If-None-Match' => '"another"']);
        $this->assertSame(200, $stale->getStatusCode());
    }

    public function testAPrivateOrUnknownItemOrAnUnknownFormatIsNotFound(): void
    {
        $this->assertSame(404, $this->dispatch(8, 'bibtex')->getStatusCode(), 'a private item');
        $this->assertSame('', $this->dispatch(8, 'bibtex')->getContent());
        $this->assertSame(404, $this->dispatch(9, 'bibtex')->getStatusCode(), 'an id the API does not answer');
        $this->assertSame(404, $this->dispatch(7, 'docx')->getStatusCode(), 'a format not offered');
    }

    public function testTheDownloadsAreOffWhenTheCitationTagsAre(): void
    {
        $this->settings->set('dre_seo_citation_meta', '0');

        $this->assertSame(404, $this->dispatch(7, 'bibtex')->getStatusCode());
    }

    public function testDownloadLinksAreAssembledFromTheModulesRoute(): void
    {
        $router = TreeRouteStack::factory(['routes' => [
            CitationExport::ROUTE => $this->config['router']['routes'][CitationExport::ROUTE],
        ]]);
        $this->assertInstanceOf(TreeRouteStack::class, $router);
        $router->setRequestUri(new HttpUri('https://example.test/'));
        // An Omeka installed under a path keeps it in the link.
        $router->setBaseUrl('/omeka');
        $view = new PhpRenderer();
        $url = $view->getHelperPluginManager()->get('url');
        $this->assertInstanceOf(Url::class, $url);
        $url->setRouter($router);

        $this->assertSame('https://example.test/omeka/cite/7/bibtex', CitationExport::url($view, 7, 'bibtex'));
    }
}
