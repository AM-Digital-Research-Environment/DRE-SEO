<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Omeka\Settings\SiteSettings;

/** Omeka's per-site settings, held in memory and keyed by site id. */
final class InMemorySiteSettings extends SiteSettings
{
    private int $currentTarget = 0;

    /** @var array<int,array<string,mixed>> */
    private array $values = [];

    public function __construct()
    {
    }

    /** @param int|string $targetId */
    public function setTargetId($targetId): void
    {
        $this->currentTarget = (int) $targetId;
    }

    /**
     * @param string $id
     * @param mixed $default
     * @param int|string|null $targetId
     */
    public function get($id, $default = null, $targetId = null): mixed
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        return $this->values[$target][strtolower((string) $id)] ?? $default;
    }

    /**
     * @param string $id
     * @param mixed $value
     * @param int|string|null $targetId
     */
    public function set($id, $value, $targetId = null): void
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        $this->values[$target][strtolower((string) $id)] = $value;
    }

    /**
     * @param string $id
     * @param int|string|null $targetId
     */
    public function delete($id, $targetId = null): void
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        unset($this->values[$target][strtolower((string) $id)]);
    }
}
