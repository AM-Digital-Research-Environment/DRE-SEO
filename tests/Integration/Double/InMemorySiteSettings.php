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

    public function setTargetId($targetId)
    {
        $this->currentTarget = (int) $targetId;
    }

    public function get($id, $default = null, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        return $this->values[$target][strtolower((string) $id)] ?? $default;
    }

    public function set($id, $value, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        $this->values[$target][strtolower((string) $id)] = $value;
    }

    public function delete($id, $targetId = null)
    {
        $target = $targetId === null ? $this->currentTarget : (int) $targetId;
        unset($this->values[$target][strtolower((string) $id)]);
    }
}
