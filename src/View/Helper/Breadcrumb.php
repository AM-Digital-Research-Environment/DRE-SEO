<?php
declare(strict_types=1);

namespace DRESeo\View\Helper;

use DRESeo\Service\BreadcrumbTrail;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * dreBreadcrumb($resource) — the trail the theme shows above a record or an
 * item set:
 *
 *   [['name' => 'AMIRA', 'url' => 'https://…/s/amira/page/home'],
 *    ['name' => 'Publications', 'url' => 'https://…/s/amira/page/publications'],
 *    ['name' => 'Das Archiv als Heimat …', 'url' => 'https://…/s/amira/item/41980']]
 *
 * A research item is placed under its project, which is placed under its own
 * landing page (Home › Research projects › project › item); see
 * {@see BreadcrumbTrail} for the rules.
 *
 * It is the trail the page's `BreadcrumbList` states (the same
 * BreadcrumbTrail builds both), so the visible breadcrumb and the structured
 * data cannot disagree. The theme
 * owns the markup. Null outside a site, or when the record has no page in it;
 * the theme then keeps its own fallback, as it does without the module.
 */
class Breadcrumb extends AbstractHelper
{
    public function __construct(private readonly BreadcrumbTrail $trail)
    {
    }

    /** @return array<int,array{name:string,url:string}>|null */
    public function __invoke(AbstractResourceEntityRepresentation $resource): ?array
    {
        /** @var PhpRenderer $view */
        $view = $this->getView();
        try {
            $site = $view->currentSite();
            if (!$site instanceof SiteRepresentation) {
                return null;
            }
            return $this->trail->trail($view, $resource, $site, $resource->siteUrl($site->slug(), true));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
