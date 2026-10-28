<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\HeadMetadata;
use DRESeo\Test\Integration\Double\CurrentSiteHelper;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\View\Helper\Doctype;
use Laminas\View\Renderer\PhpRenderer;

/**
 * What the head listeners write, read back through the real Laminas head
 * helpers as the layout prints them: a full-text search on the item browse
 * page, then a JSON-LD block that tries to break out of its script element.
 */
final class HeadOutputTest extends OmekaTestCase
{
    /** @var array<string,mixed> */
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['REQUEST_URI'] = '/s/amira/item?fulltext=history';
        $_SERVER['QUERY_STRING'] = 'fulltext=history';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /** The browse page, after the browse and layout listeners have run. */
    private function browsePage(): PhpRenderer
    {
        $view = new PhpRenderer();
        $view->doctype(Doctype::HTML5);
        $view->headTitle()->append('Browse');
        $view->getHelperPluginManager()->setService('currentSite', new CurrentSiteHelper(new FixedSite()));

        $events = new EventManager($this->sharedEvents, ['Omeka\\Controller\\Site\\Item']);
        $events->triggerEvent(new Event('view.browse.after', $view, ['view' => $view]));
        $events->triggerEvent(new Event('view.layout', $view, ['view' => $view]));

        return $view;
    }

    public function testTheBrowseAndLayoutListenersWriteThroughTheHeadHelpers(): void
    {
        $view = $this->browsePage();
        $headMeta = (string) $view->headMeta();
        $decodedHeadMeta = html_entity_decode($headMeta, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decodedHeadLink = html_entity_decode((string) $view->headLink(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $robots = "Browse listener did not emit a robots directive through Laminas HeadMeta:\n" . $headMeta;
        $this->assertStringContainsString('name="robots"', $decodedHeadMeta, $robots);
        $this->assertStringContainsString('content="noindex, follow"', $decodedHeadMeta, $robots);

        $openGraph = 'Layout listener did not emit the site Open Graph metadata.';
        $this->assertStringContainsString('property="og:site_name"', $decodedHeadMeta, $openGraph);
        $this->assertStringContainsString('AMIRA Smoke', $decodedHeadMeta, $openGraph);
        $this->assertStringContainsString('twitter:card', $decodedHeadMeta, 'Layout listener did not emit Twitter metadata.');

        $canonical = 'Browse listener did not emit the canonical URL through Laminas HeadLink.';
        $this->assertStringContainsString('rel="canonical"', $decodedHeadLink, $canonical);
        $this->assertStringContainsString('https://example.test/s/amira/item?fulltext=history', $decodedHeadLink, $canonical);
    }

    public function testJsonLdGoesThroughHeadScriptAndCannotCloseItsElement(): void
    {
        $view = $this->browsePage();
        $this->services->get(HeadMetadata::class)->addJsonLd($view, [
            '@context' => 'https://schema.org',
            'name' => '</script>',
        ]);
        $decodedHeadScript = html_entity_decode((string) $view->headScript(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('application/ld+json', $decodedHeadScript, 'JSON-LD did not use HeadScript.');
        $this->assertStringNotContainsString('//<!--', $decodedHeadScript, 'HeadScript wrapped JSON-LD in a JavaScript comment.');
        // JSON_HEX_TAG escapes the angle brackets, so the value cannot close the
        // script element; the slash may stay literal (URLs read cleanly).
        $this->assertStringContainsString('\\u003C/script\\u003E', $decodedHeadScript, 'JSON-LD script-breakout text was not escaped.');
        $this->assertSame(1, substr_count($decodedHeadScript, '</script>'), 'JSON-LD content closed its own script element.');
    }
}
