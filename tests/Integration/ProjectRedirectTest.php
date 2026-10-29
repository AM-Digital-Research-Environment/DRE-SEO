<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\EventManager\EventManager;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Api\Response as ApiResponse;

/**
 * The project item-set redirect, through a real MvcEvent: a project's
 * item-set page answers 301 with the project's page; nothing else does.
 */
final class ProjectRedirectTest extends OmekaTestCase
{
    private ServiceManager $redirectServices;

    protected function setUp(): void
    {
        parent::setUp();

        // Item set 20 is part of the public project 30; item set 3 is part of
        // nothing; any other id is not found.
        $project = new class extends ItemRepresentation {
            public function __construct()
            {
            }

            public function id(): int
            {
                return 30;
            }

            public function isPublic(): bool
            {
                return true;
            }

            public function resourceClass(): object
            {
                return new class {
                    public function term(): string
                    {
                        return 'foaf:Project';
                    }
                };
            }

            /**
             * @param string|null $siteSlug
             * @param bool $canonical
             */
            public function siteUrl($siteSlug = null, $canonical = false): string
            {
                return 'https://example.test/s/' . $siteSlug . '/item/30';
            }
        };
        $link = new class ($project) extends ValueRepresentation {
            public function __construct(private readonly ItemRepresentation $target)
            {
            }

            public function valueResource(): ItemRepresentation
            {
                return $this->target;
            }
        };
        $itemSet = static fn (int $id, array $partOf): object => new class ($id, $partOf) extends ItemSetRepresentation {
            /** @param array<int,object> $partOf */
            public function __construct(private readonly int $setId, private readonly array $partOf)
            {
            }

            public function id(): int
            {
                return $this->setId;
            }

            /**
             * @param string $term
             * @param array<string,mixed> $options
             */
            public function value($term, array $options = []): mixed
            {
                $values = $term === 'dcterms:isPartOf' ? $this->partOf : [];
                return empty($options['all']) ? ($values[0] ?? null) : $values;
            }
        };
        $api = new class ([20 => $itemSet(20, [$link]), 3 => $itemSet(3, [])]) extends Manager {
            /** @param array<int,object> $itemSets */
            public function __construct(private readonly array $itemSets)
            {
            }

            /**
             * @param string $resource
             * @param int|string|array<string,mixed> $id
             * @param array<string,mixed> $data
             * @param array<string,mixed> $options
             */
            public function read($resource, $id, array $data = [], array $options = []): ApiResponse
            {
                if ($resource !== 'item_sets' || !isset($this->itemSets[$id])) {
                    throw new NotFoundException('Not found');
                }
                return new ApiResponse($this->itemSets[$id]);
            }
        };

        $this->redirectServices = new ServiceManager($this->config['service_manager']);
        $this->redirectServices->setService('Config', $this->config);
        $this->redirectServices->setService('Omeka\\ApiManager', $api);
        $this->redirectServices->setService('Omeka\\Settings\\Site', $this->siteSettings);
    }

    /** What the module's route listener answers for one request on site 1. */
    private function redirect(string $method, string $route, int $itemSetId): ?Response
    {
        $request = new Request();
        $request->setMethod($method);
        $response = new Response();
        $routeMatch = new RouteMatch(['item-set-id' => (string) $itemSetId]);
        $routeMatch->setMatchedRouteName($route);
        $event = new MvcEvent();
        $event->setApplication(new Application($this->redirectServices, new EventManager(), $request, $response));
        $event->setRequest($request);
        $event->setResponse($response);
        $event->setRouteMatch($routeMatch);
        $event->setViewModel(new ViewModel(['site' => new FixedSite()]));
        return $this->module->redirectProjectItemSet($event);
    }

    public function testAProjectItemSetPageRedirectsToTheProjectForGetAndHead(): void
    {
        $moved = $this->redirect('GET', 'site/item-set', 20);
        $this->assertNotNull($moved, 'A project item-set page did not redirect to the project.');
        $this->assertSame(301, $moved->getStatusCode(), 'A project item-set page did not redirect to the project.');
        $this->assertSame(
            'https://example.test/s/amira/item/30',
            $moved->getHeaders()->get('Location')->getFieldValue(),
            'A project item-set page did not redirect to the project.'
        );

        $this->assertSame(301, $this->redirect('HEAD', 'site/item-set', 20)?->getStatusCode(), 'A HEAD request was not redirected.');
    }

    public function testNothingElseRedirects(): void
    {
        $this->assertNull($this->redirect('POST', 'site/item-set', 20), 'A POST was redirected.');
        $this->assertNull($this->redirect('GET', 'site/resource', 20), 'Another route was redirected.');
        $this->assertNull($this->redirect('GET', 'site/item-set', 3), 'An item set with no project was redirected.');
        $this->assertNull($this->redirect('GET', 'site/item-set', 404), 'An unreadable item set was redirected.');
    }

    public function testTheSiteSettingTurnsTheRedirectOff(): void
    {
        $this->siteSettings->set('dre_seo_project_redirect', '0', 1);

        $this->assertNull($this->redirect('GET', 'site/item-set', 20), 'The redirect ignored the site setting.');
    }
}
