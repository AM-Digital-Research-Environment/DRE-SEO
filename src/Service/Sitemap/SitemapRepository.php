<?php
declare(strict_types=1);

namespace DRESeo\Service\Sitemap;

use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;

/**
 * The sitemap's data access: public resource ids and modified timestamps for
 * one site, read with one lean DBAL query per type.
 *
 * Deliberately not the ORM or the API layer — hydrating thousands of item
 * representations to emit as many `<loc>` elements would cost seconds and
 * megabytes for two columns per row. Every query is scoped to the site and to
 * public resources, and is plain SQL that MySQL/MariaDB and SQLite both run.
 *
 * A failing query is logged with the site id and then thrown: the cache and
 * the controller decide what a failure means (a recent cached copy, else a
 * 503), so a database outage is never cached as an empty sitemap.
 */
final class SitemapRepository implements SitemapRepositoryInterface
{
    private const ITEM = 'Omeka\\Entity\\Item';
    private const ITEM_SET = 'Omeka\\Entity\\ItemSet';

    /**
     * Keeps an item set (`r`) only when it stands for no public research
     * project: none of its public `dcterms:isPartOf` values links to a public
     * `foaf:Project` item, and no public `foaf:Project` item carries its
     * `dre:id` — the rule {@see \DRESeo\Service\ProjectResolver::projectOfItemSet()}
     * applies, as two correlated subqueries of the one query. Binds :item.
     */
    private const NOT_A_PROJECT_SET = "
        AND NOT EXISTS (
            SELECT 1 FROM value v
            JOIN property p ON p.id = v.property_id
            JOIN vocabulary pv ON pv.id = p.vocabulary_id
            JOIN resource pr ON pr.id = v.value_resource_id
            JOIN resource_class c ON c.id = pr.resource_class_id
            JOIN vocabulary cv ON cv.id = c.vocabulary_id
            WHERE v.resource_id = r.id AND v.is_public = 1
              AND pv.prefix = 'dcterms' AND p.local_name = 'isPartOf'
              AND pr.resource_type = :item AND pr.is_public = 1
              AND cv.prefix = 'foaf' AND c.local_name = 'Project'
        )
        AND NOT EXISTS (
            SELECT 1 FROM value sv
            JOIN property sp ON sp.id = sv.property_id
            JOIN vocabulary spv ON spv.id = sp.vocabulary_id
            JOIN value iv ON iv.property_id = sv.property_id AND iv.value = sv.value AND iv.is_public = 1
            JOIN resource ir ON ir.id = iv.resource_id
            JOIN resource_class ic ON ic.id = ir.resource_class_id
            JOIN vocabulary icv ON icv.id = ic.vocabulary_id
            WHERE sv.resource_id = r.id AND sv.is_public = 1
              AND spv.prefix = 'dre' AND sp.local_name = 'id'
              AND ir.resource_type = :item AND ir.is_public = 1
              AND icv.prefix = 'foaf' AND ic.local_name = 'Project'
        )";

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function countItems(int $siteId): int
    {
        return (int) $this->query('count public items', $siteId, fn (): mixed => $this->connection->fetchOne(
            'SELECT COUNT(*) FROM resource r
             JOIN item_site isi ON isi.item_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s',
            ['t' => self::ITEM, 's' => $siteId]
        ));
    }

    public function fetchItems(int $siteId, int $offset, int $limit): array
    {
        // LIMIT/OFFSET are inlined as already-cast integers: PDO refuses bound
        // parameters there under emulated prepares, and casting makes it safe.
        $limit = max(0, $limit);
        $offset = max(0, $offset);
        return $this->query('fetch public items', $siteId, fn (): array => $this->connection->fetchAllAssociative(
            'SELECT r.id, r.modified FROM resource r
             JOIN item_site isi ON isi.item_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s
             ORDER BY r.id LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['t' => self::ITEM, 's' => $siteId]
        ));
    }

    public function fetchItemSets(int $siteId, bool $excludeProjectSets = false): array
    {
        return $this->query('fetch public item sets', $siteId, fn (): array => $this->connection->fetchAllAssociative(
            'SELECT r.id, r.modified FROM resource r
             JOIN site_item_set sis ON sis.item_set_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s'
             . ($excludeProjectSets ? self::NOT_A_PROJECT_SET : '')
             . ' ORDER BY r.id',
            $this->itemSetParams($siteId, $excludeProjectSets)
        ));
    }

    public function fetchPages(int $siteId): array
    {
        return $this->query('fetch public site pages', $siteId, fn (): array => $this->connection->fetchAllAssociative(
            'SELECT id, slug, modified FROM site_page
             WHERE site_id = :s AND is_public = 1 ORDER BY id',
            ['s' => $siteId]
        ));
    }

    public function latestItemChange(int $siteId, int $offset, int $limit): ?string
    {
        return $this->latest(
            'SELECT MAX(t.modified) FROM (SELECT r.modified FROM resource r
             JOIN item_site isi ON isi.item_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s
             ORDER BY r.id LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset) . ') t',
            ['t' => self::ITEM, 's' => $siteId],
            $siteId
        );
    }

    public function latestItemSetChange(int $siteId, bool $excludeProjectSets = false): ?string
    {
        return $this->latest(
            'SELECT MAX(r.modified) FROM resource r
             JOIN site_item_set sis ON sis.item_set_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s'
             . ($excludeProjectSets ? self::NOT_A_PROJECT_SET : ''),
            $this->itemSetParams($siteId, $excludeProjectSets),
            $siteId
        );
    }

    public function latestPageChange(int $siteId): ?string
    {
        return $this->latest(
            'SELECT MAX(modified) FROM site_page WHERE site_id = :s AND is_public = 1',
            ['s' => $siteId],
            $siteId
        );
    }

    /** @param array<string,mixed> $params */
    private function latest(string $query, array $params, int $siteId): ?string
    {
        $value = $this->query('read a sitemap lastmod', $siteId, fn (): mixed => $this->connection->fetchOne($query, $params));
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function itemSetParams(int $siteId, bool $excludeProjectSets): array
    {
        $params = ['t' => self::ITEM_SET, 's' => $siteId];
        if ($excludeProjectSets) {
            $params['item'] = self::ITEM;
        }
        return $params;
    }

    /**
     * Run one query, logging a failure with the site it was for before it
     * propagates.
     *
     * @template T
     * @param callable():T $run
     * @return T
     */
    private function query(string $operation, int $siteId, callable $run): mixed
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            $this->logger->err(sprintf('DRESeo: failed to %s for sitemap generation.', $operation), [
                'site_id' => $siteId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
