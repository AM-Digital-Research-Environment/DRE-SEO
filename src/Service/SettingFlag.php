<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Settings\Settings;

/**
 * Reads the module's checkbox settings. They are stored as the strings '1' and
 * '0' by the config form, but a setting written by a script or an older
 * release may hold an int or a bool, so every reader accepts all three.
 */
final class SettingFlag
{
    public static function isOn(Settings $settings, string $key, bool $default = false): bool
    {
        $value = $settings->get($key, $default ? '1' : '0');

        return $value === '1' || $value === 1 || $value === true;
    }
}
