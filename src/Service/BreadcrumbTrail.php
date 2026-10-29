<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * A record's place in the site, from its home page: the trail the theme shows
 * through `dreBreadcrumb()` ({@see \DRESeo\View\Helper\Breadcrumb}) and the
 * `BreadcrumbList` the page states ({@see StructuredData::breadcrumb()}). One
 * builder for both, so the visible breadcrumb and the structured data cannot
 * disagree.
 *
 *     research item   Home › Research projects › its project › the item
 *     other item      Home › its kind's landing page › the item
 *                     (Research projects, Publications, People …)
 *                     else Home › its item set › the item
 *     media           Home › … › its item › the media (the item's trail)
 *     item set        Home › its landing page › the item set
 *     other           Home › the record
 *
 * A research item is one whose `dcterms:isPartOf` links to a public project
 * ({@see ProjectResolver}); the project is placed as any item is. An item's
 * landing page is that of the first of its item sets with one
 * ({@see LandingPages}), the sets the site uses first. Without one, an item is
 * placed in its item set — the first the site uses, else its first — or,
 * when that item set's page redirects to its project, under the project.
 * Every URL is absolute.
 */
final class BreadcrumbTrail
{
    /** How deep a record's parents are followed: media › item › project › landing. */
    private const TRAIL_DEPTH = 3;

    /**
     * @param LandingPages|null    $landingPages null places records in their item sets only
     * @param ProjectResolver|null $projects     null places no record under a project
     */
    public function __construct(
        private readonly ?LandingPages $landingPages = null,
        private readonly ?ProjectResolver $projects = null,
    ) {
    }

    /**
     * The trail, each crumb a name and an absolute URL, ending at the record's
     * own page. Null when the record has no page in the site.
     *
     * @return array<int,array{name:string,url:string}>|null
     */
    public function trail(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical
    ): ?array {
        if (!$canonical) {
            return null;
        }
        $home = SiteHome::absoluteUrl($view, $site);
        $trail = [['name' => $site->title(), 'url' => $home]];
        $seen = [$home => true, $canonical => true];
        foreach ($this->trailParents($resource, $site, 0) as $crumb) {
            // A crumb names a page once, and never the record's own page.
            if (!isset($seen[$crumb['url']])) {
                $seen[$crumb['url']] = true;
                $trail[] = $crumb;
            }
        }
        $trail[] = ['name' => (string) $resource->displayTitle(), 'url' => $canonical];

        return $trail;
    }

    /**
     * The trail as a schema.org BreadcrumbList; null when there is none.
     *
     * @return array<mixed>|null
     */
    public function breadcrumbList(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical
    ): ?array {
        $trail = $this->trail($view, $resource, $site, $canonical);
        if ($trail === null) {
            return null;
        }
        $elements = [];
        foreach ($trail as $index => $crumb) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * The crumbs between the home page and a record.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function trailParents(AbstractResourceEntityRepresentation $resource, SiteRepresentation $site, int $depth): array
    {
        if ($depth > self::TRAIL_DEPTH) {
            return [];
        }
        try {
            if ($resource instanceof MediaRepresentation) {
                return $this->under($resource->item(), $site, $depth);
            }
            if ($resource instanceof ItemSetRepresentation) {
                $landing = $this->landingPages?->forItemSets([$resource], $site);
                return $landing !== null ? [$landing] : [];
            }
            if (!$resource instanceof ItemRepresentation) {
                return [];
            }
            $project = $this->projects?->projectOf($resource);
            if ($project !== null) {
                return $this->under($project, $site, $depth);
            }
            $itemSets = $this->itemSetsInOrder($resource, $site);
            $landing = $this->landingPages?->forItemSets($itemSets, $site);
            if ($landing !== null) {
                return [$landing];
            }
            $collection = $itemSets[0] ?? null;
            if ($collection === null) {
                return [];
            }
            // An item set whose page redirects to its project is named by
            // the project, so the crumb is not a redirect.
            $project = $this->projects?->redirectTarget($collection, $site);
            if ($project !== null && $project->id() !== $resource->id()) {
                return $this->under($project, $site, $depth);
            }
            $crumb = $this->crumb($collection, $site);
            return $crumb !== null ? [$crumb] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The crumbs of a record placed under $parent: the parent's own trail,
     * then the parent.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function under(AbstractResourceEntityRepresentation $parent, SiteRepresentation $site, int $depth): array
    {
        $crumb = $this->crumb($parent, $site);
        if ($crumb === null) {
            return [];
        }
        return [...$this->trailParents($parent, $site, $depth + 1), $crumb];
    }

    /** @return array{name:string,url:string}|null */
    private function crumb(AbstractResourceEntityRepresentation $resource, SiteRepresentation $site): ?array
    {
        try {
            $name = trim((string) $resource->displayTitle());
            $url = (string) $resource->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }

        return $name !== '' && $url !== '' ? ['name' => $name, 'url' => $url] : null;
    }

    /**
     * An item's item sets, those the site uses first.
     *
     * @return ItemSetRepresentation[]
     */
    private function itemSetsInOrder(ItemRepresentation $item, SiteRepresentation $site): array
    {
        $itemSets = array_values($item->itemSets());
        if ($itemSets === []) {
            return [];
        }
        $inSite = [];
        foreach ($site->siteItemSets() as $siteItemSet) {
            $inSite[$siteItemSet->itemSet()->id()] = true;
        }
        $used = $unused = [];
        foreach ($itemSets as $itemSet) {
            if (isset($inSite[$itemSet->id()])) {
                $used[] = $itemSet;
            } else {
                $unused[] = $itemSet;
            }
        }

        return [...$used, ...$unused];
    }
}
