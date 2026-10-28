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

    public function get($id, $default = null)
    {
        return $this->values[strtolower((string) $id)] ?? $default;
    }

    public function set($id, $value)
    {
        $this->values[strtolower((string) $id)] = $value;
    }

    public function delete($id)
    {
        unset($this->values[strtolower((string) $id)]);
    }
}
