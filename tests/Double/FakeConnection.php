<?php
declare(strict_types=1);

namespace DRESeo\Test\Double;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * The DBAL connection the sitemap generator queries, in memory: each query is
 * recognised by its shape and answered from the arrays a test fills in, and
 * every query is recorded.
 */
final class FakeConnection extends Connection
{
    /** @var array<int,int> */
    public array $itemCounts = [];

    /** @var array<int,array<int,array{id:int,modified:?string}>> */
    public array $items = [];

    /** @var array<int,array<int,array{id:int,modified:?string}>> */
    public array $itemSets = [];

    /** @var array<int,array<int,array{id:int,slug:string,modified:?string}>> */
    public array $pages = [];

    /** @var int[] item sets that stand for a public research project */
    public array $projectSetIds = [];

    /** @var string[] */
    public array $queries = [];

    /** @var array<string,?string> latest modification per child: 'pages', 'item-sets', 'items' */
    public array $latest = [];

    public function fetchOne(string $query, array $params = []): mixed
    {
        $this->queries[] = $query;
        if (str_contains($query, 'MAX(')) {
            return match (true) {
                str_contains($query, 'FROM site_page') => $this->latest['pages'] ?? null,
                str_contains($query, 'site_item_set') => $this->latest['item-sets'] ?? null,
                default => $this->latest['items'] ?? null,
            };
        }
        return $this->itemCounts[(int) ($params['s'] ?? 0)] ?? 0;
    }

    public function fetchAllAssociative(string $query, array $params = []): array
    {
        $this->queries[] = $query;
        $siteId = (int) ($params['s'] ?? 0);
        if (str_contains($query, 'JOIN item_site')) {
            preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $matches);
            return array_slice(
                $this->items[$siteId] ?? [],
                (int) ($matches[2] ?? 0),
                (int) ($matches[1] ?? 0)
            );
        }
        if (str_contains($query, 'JOIN site_item_set')) {
            $rows = $this->itemSets[$siteId] ?? [];
            if (str_contains($query, 'NOT EXISTS')) {
                // The item sets that stand for a public project, as the real
                // query would find them.
                Assert::assertSame('Omeka\Entity\Item', $params['item'] ?? null, 'the exclusion binds :item');
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => !in_array((int) $row['id'], $this->projectSetIds, true)
                ));
            }
            return $rows;
        }
        if (str_contains($query, 'FROM site_page')) {
            return $this->pages[$siteId] ?? [];
        }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}
