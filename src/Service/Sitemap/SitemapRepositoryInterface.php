<?php
declare(strict_types=1);

namespace DRESeo\Service\Sitemap;

/**
 * Read model consumed by {@see \DRESeo\Service\SitemapGenerator}.
 *
 * Keeping the generator on this narrow contract makes its URL-selection
 * policy testable without Doctrine or an Omeka database. The production
 * implementation is {@see SitemapRepository}; tests can supply an in-memory
 * repository. Every method answers for public resources of one site, and
 * throws when the database does: an outage must never read as an empty
 * collection.
 */
interface SitemapRepositoryInterface
{
    public function countItems(int $siteId): int;

    /** @return array<array{id:int,modified:?string}> public items in id order */
    public function fetchItems(int $siteId, int $offset, int $limit): array;

    /**
     * @param bool $excludeProjectSets leave out the item sets that stand for
     *        a public research project
     * @return array<array{id:int,modified:?string}>
     */
    public function fetchItemSets(int $siteId, bool $excludeProjectSets = false): array;

    /** @return array<array{id:int,slug:string,modified:?string}> public pages, home included */
    public function fetchPages(int $siteId): array;

    /** The latest modification among one chunk of fetchItems(), or null. */
    public function latestItemChange(int $siteId, int $offset, int $limit): ?string;

    /** The latest modification among fetchItemSets(), or null. */
    public function latestItemSetChange(int $siteId, bool $excludeProjectSets = false): ?string;

    /** The latest modification among fetchPages(), or null. */
    public function latestPageChange(int $siteId): ?string;
}
