<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * Recognises a search results page from its route: core's site search
 * (`/s/{slug}/index/search`) and its advanced-search forms
 * (`/s/{slug}/item/search`, …), and DRE Search's results page
 * (`/s/{slug}/dre-search`). Such a page lists records already in the sitemap,
 * for any query a visitor or a crawler makes up, and is kept out of the index.
 */
final class SearchPage
{
    private const DRE_SEARCH_ROUTE = 'site/dre-search';
    private const DRE_SEARCH_CONTROLLER = 'DRESearch\Controller\SearchController';

    public static function matches(string $routeName, string $controller, string $action): bool
    {
        if ($routeName === self::DRE_SEARCH_ROUTE || $controller === self::DRE_SEARCH_CONTROLLER) {
            return true;
        }
        return str_starts_with($routeName, 'site') && strtolower($action) === 'search';
    }
}
