<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Service\HeadMetadata;
use DRESeo\Service\Signposting;
use DRESeo\Test\Integration\Double\CurrentSiteHelper;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\Http\Header\MultipleHeaderInterface;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\Application;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * The Signposting `Link` header against a real Laminas HTTP response: added
 * once per page by the layout listener, beside a Link header another module
 * set (Laminas registers no multi-header class for Link, so a plain
 * addHeader() replaced it), and logged rather than thrown when there is no
 * response to add it to.
 */
final class LinkHeaderTest extends OmekaTestCase
{
    private const PAGE = 'https://example.test/s/amira/item/7';

    /** @var array<int,array{rel:string,href:string,type?:string}> */
    private const LINKS = [
        ['rel' => 'cite-as', 'href' => self::PAGE],
        ['rel' => 'describedby', 'href' => 'https://example.test/cite/7/bibtex', 'type' => 'application/x-bibtex'],
        ['rel' => 'type', 'href' => 'https://schema.org/Thesis'],
    ];

    /**
     * Head metadata that signposts the given links and writes nothing else:
     * what reaches the header is the listeners' doing, not the head's.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    private function signposting(array $links): void
    {
        $double = new class ($links) extends HeadMetadata {
            /** @param array<int,array{rel:string,href:string,type?:string}> $links */
            public function __construct(private readonly array $links)
            {
            }

            public function applyResource(PhpRenderer $view, AbstractResourceEntityRepresentation $resource, SiteRepresentation $site): void
            {
            }

            public function applyBrowse(PhpRenderer $view, SiteRepresentation $site, ?ItemSetRepresentation $itemSet = null): void
            {
            }

            public function applyGlobals(PhpRenderer $view, ?SiteRepresentation $site, bool $isSearch = false): void
            {
            }

            public function signposts(): array
            {
                return $this->links;
            }
        };
        $this->services->setAllowOverride(true);
        $this->services->setService(HeadMetadata::class, $double);
    }

    private function respondWith(?Response $response): void
    {
        $application = $this->createStub(Application::class);
        if ($response !== null) {
            $application->method('getResponse')->willReturn($response);
        } else {
            $application->method('getResponse')->willThrowException(new \RuntimeException('no response'));
        }
        $this->services->setAllowOverride(true);
        $this->services->setService('Application', $application);
    }

    /**
     * A record page whose template triggers both action events (a theme or
     * a module may), then the layout.
     */
    private function render(): void
    {
        $view = new PhpRenderer();
        $view->getHelperPluginManager()->setService('currentSite', new CurrentSiteHelper(new FixedSite()));
        $view->setVars(['item' => self::withoutConstructor(ItemRepresentation::class)]);

        $events = new EventManager($this->sharedEvents, ['Omeka\\Controller\\Site\\Item']);
        $events->triggerEvent(new Event('view.show.after', $view, ['view' => $view]));
        $events->triggerEvent(new Event('view.browse.after', $view, ['view' => $view]));
        $events->triggerEvent(new Event('view.layout', $view, ['view' => $view]));
    }

    public function testTheLayoutListenerAddsTheLinkHeaderOnceBesideAnotherModules(): void
    {
        $response = new Response();
        // Another module's Link header, set as a raw line before ours.
        $response->getHeaders()->addHeaderLine('Link', '<https://example.test/asset/app.css>; rel="preload"');
        $this->respondWith($response);
        $this->signposting(self::LINKS);

        $this->render();

        $links = $response->getHeaders()->get('Link');
        self::assertInstanceOf(\ArrayIterator::class, $links, 'both Link headers survive');
        $values = [];
        foreach ($links as $header) {
            self::assertInstanceOf(MultipleHeaderInterface::class, $header, 'each is sent without replacing the other');
            $values[] = $header->getFieldValue();
        }
        self::assertSame([
            '<https://example.test/asset/app.css>; rel="preload"',
            Signposting::header(self::LINKS),
        ], $values, 'ours once, though the show and the browse listener both ran');
        self::assertStringStartsWith('<' . self::PAGE . '>; rel="cite-as", ', $values[1]);
        self::assertSame(2, substr_count($response->getHeaders()->toString(), "Link: "));
    }

    /** Past the budget, links stay in the head only, and the header stays under nginx's buffer. */
    public function testTheHeaderKeepsToItsBudget(): void
    {
        $response = new Response();
        $this->respondWith($response);
        $links = self::LINKS;
        for ($i = 0; $i < 60; $i++) {
            $links[] = ['rel' => 'item', 'href' => 'https://example.test/files/original/' . sha1((string) $i) . '.jpg', 'type' => 'image/jpeg'];
        }
        $this->signposting($links);

        $this->render();

        $header = $response->getHeaders()->get('Link');
        self::assertInstanceOf(\ArrayIterator::class, $header);
        $value = $header[0]->getFieldValue();
        self::assertLessThanOrEqual(Signposting::HEADER_BUDGET, strlen($value));
        self::assertStringStartsWith(Signposting::header(self::LINKS) . ', ', $value);
        self::assertLessThan(60, substr_count($value, 'rel="item"'));
    }

    public function testAPageWithoutSignpostsGetsNoLinkHeader(): void
    {
        $response = new Response();
        $this->respondWith($response);
        $this->signposting([]);

        $this->render();

        self::assertFalse($response->getHeaders()->has('Link'));
    }

    public function testAMissingResponseIsLoggedAndThePageStillRenders(): void
    {
        $this->respondWith(null);
        $this->signposting(self::LINKS);

        $this->render();

        $warnings = array_values(array_filter(
            $this->logWriter->events,
            static fn (array $event): bool => $event['priorityName'] === 'WARN'
        ));
        self::assertSame(['DRESeo: could not add the Signposting Link header.'], array_column($warnings, 'message'));
        self::assertInstanceOf(\RuntimeException::class, $warnings[0]['extra']['exception'] ?? null);
    }
}
