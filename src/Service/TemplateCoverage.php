<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;

/**
 * The resource templates items use that the module's template maps do not
 * name, for Admin → SEO.
 *
 * Dispatch is by template id, and an unmapped template is not an error: its
 * items fall back to a generic `CreativeWork` and a generic citation. That
 * silence is how the publication templates 24–32 went live unmapped, so the
 * dashboard lists them instead. A template is listed when either map
 * (`structured_data.template_types`, `citation.template_kinds`) lacks it.
 */
final class TemplateCoverage
{
    /**
     * @param array<int|string,string> $templateTypes schema.org type per template id
     * @param array<int|string,string> $templateKinds citation kind per template id
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $templateTypes,
        private readonly array $templateKinds,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The unmapped templates in use, by id, with their label, how many items
     * use them and which maps lack them; null when the database cannot say.
     *
     * @return list<array{id:int,label:string,items:int,missing:list<string>}>|null
     */
    public function unmapped(): ?array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT r.resource_template_id AS id, MAX(t.label) AS label, COUNT(*) AS items'
                . ' FROM resource r LEFT JOIN resource_template t ON t.id = r.resource_template_id'
                . ' WHERE r.resource_type = ? AND r.resource_template_id IS NOT NULL'
                . ' GROUP BY r.resource_template_id ORDER BY r.resource_template_id',
                ['Omeka\Entity\Item']
            );
        } catch (\Throwable $e) {
            $this->logger?->warn('[DRESeo] Could not list the resource templates in use: ' . $e->getMessage());
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $missing = [];
            if (!isset($this->templateTypes[$id])) {
                $missing[] = 'schema.org type';
            }
            if (!isset($this->templateKinds[$id])) {
                $missing[] = 'citation kind';
            }
            if ($missing !== []) {
                $out[] = [
                    'id' => $id,
                    'label' => (string) ($row['label'] ?? ''),
                    'items' => (int) $row['items'],
                    'missing' => $missing,
                ];
            }
        }
        return $out;
    }
}
