<?php
declare(strict_types=1);

namespace DRESeo\Listener;

use DRESeo\Service\ProjectResolver;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * The route listener that sends a research project's item-set page to the
 * project item, the project's one public page.
 *
 * `/s/{site}/item-set/{id}` (GET or HEAD) answers 301 with the project
 * item's page in the same site, when the item set stands for a public project
 * and the site has not switched the redirect off ({@see ProjectResolver}).
 * Returning the response ends the route event, so neither the controller nor
 * the theme runs. Nothing here touches the session, and any failure leaves
 * the page as it was.
 *
 * Attached by the Module after core's route listeners (priority 1) have read
 * the site, and before dispatch. Stateless: the services come from the
 * event's application.
 */
final class ProjectItemSetRedirect
{
    public function __invoke(MvcEvent $event): ?HttpResponse
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
}
