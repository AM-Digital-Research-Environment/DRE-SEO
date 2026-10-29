<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Settings\Settings;

/**
 * Typed reads over the module's `dre_seo_*` global settings: one definition of
 * "on", and of a text setting, for services, controllers, factories and the
 * Module alike.
 *
 * Replaces the static SettingFlag helper and the private boolSetting() /
 * stringSetting() copies the head metadata and the dashboard kept of it, each
 * a place where the rule could drift. Ported from IWAC SEO, which has the same
 * settings layout.
 *
 * What counts as on: the string '1', the int 1, or true. The config form
 * stores a checkbox as '1' or '0', but a setting written by a script or an
 * older release may hold an int or a bool; all three forms are accepted
 * deliberately.
 */
final class SettingsGate
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isOn(string $key, bool $default = false): bool
    {
        $value = $this->settings->get($key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }

    /** A trimmed string setting; '' when unset. */
    public function text(string $key): string
    {
        return trim((string) ($this->settings->get($key, '') ?? ''));
    }

    /** A raw (untrimmed) string setting — for values where whitespace matters. */
    public function raw(string $key): string
    {
        return (string) ($this->settings->get($key, '') ?? '');
    }

    /**
     * An integer setting; $default only when unset. A stored blank (a number
     * field the config form saved empty) reads as 0, as it always has.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->settings->get($key);
        return $value === null ? $default : (int) $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set($key, $value);
    }
}
