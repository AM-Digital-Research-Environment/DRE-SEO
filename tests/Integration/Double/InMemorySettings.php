<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Omeka\Settings\Settings;

/**
 * Omeka's global settings, held in memory instead of the setting table. Keys
 * are case-folded as the database's collation folds them.
 */
final class InMemorySettings extends Settings
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * @param string $id
     * @param mixed $default
     */
    public function get($id, $default = null): mixed
    {
        return $this->values[strtolower((string) $id)] ?? $default;
    }

    /**
     * @param string $id
     * @param mixed $value
     */
    public function set($id, $value): void
    {
        $this->values[strtolower((string) $id)] = $value;
    }

    /** @param string $id */
    public function delete($id): void
    {
        unset($this->values[strtolower((string) $id)]);
    }
}
