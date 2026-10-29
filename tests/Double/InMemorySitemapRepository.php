<?php
declare(strict_types=1);

namespace DRESeo\Test\Double;

use DRESeo\Service\Sitemap\SitemapRepositoryInterface;
use RuntimeException;

/** A deterministic sitemap read model for policy-level generator tests. */
final class InMemorySitemapRepository implements SitemapRepositoryInterface
{
    /** @var array<array{id:int,modified:?string}> */
    public array $items = [];

    /** @var array<array{id:int,modified:?string}> */
    public array $itemSets = [];

    /** @var int[] item sets that stand for a public research project */
    public array $projectSetIds = [];

    /** @var array<array{id:int,slug:string,modified:?string}> */
    public array $pages = [];

    /** Override for testing index chunking without manufacturing thousands of rows. */
    public ?int $itemCount = null;

    /** @var array{siteId:int,offset:int,limit:int}|null */
    public ?array $lastItemFetch = null;

    /** When set, every read throws it, as the database would when down. */
    public ?RuntimeException $failure = null;

    public function countItems(int $siteId): int
    {
        $this->fail();
        return $this->itemCount ?? count($this->items);
    }

    public function fetchItems(int $siteId, int $offset, int $limit): array
    {
        $this->fail();
        $this->lastItemFetch = ['siteId' => $siteId, 'offset' => $offset, 'limit' => $limit];
        return array_slice($this->items, $offset, $limit);
    }

    public function fetchItemSets(int $siteId, bool $excludeProjectSets = false): array
    {
        $this->fail();
        if (!$excludeProjectSets) {
            return $this->itemSets;
        }
        return array_values(array_filter(
            $this->itemSets,
            fn (array $row): bool => !in_array($row['id'], $this->projectSetIds, true)
        ));
    }

    public function fetchPages(int $siteId): array
    {
        $this->fail();
        return $this->pages;
    }

    public function latestItemChange(int $siteId, int $offset, int $limit): ?string
    {
        return self::latest(array_slice($this->items, $offset, $limit));
    }

    public function latestItemSetChange(int $siteId, bool $excludeProjectSets = false): ?string
    {
        return self::latest($this->fetchItemSets($siteId, $excludeProjectSets));
    }

    public function latestPageChange(int $siteId): ?string
    {
        return self::latest($this->fetchPages($siteId));
    }

    /** @param array<array{modified:?string}> $rows */
    private static function latest(array $rows): ?string
    {
        $dates = array_filter(array_column($rows, 'modified'));
        return $dates === [] ? null : max($dates);
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
