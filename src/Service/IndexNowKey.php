<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * The one definition of a well-formed IndexNow ownership key.
 *
 * IndexNow verifies ownership by fetching `/{key}.txt` from the submitted
 * host, and the module's route only serves a hexadecimal key of 8–128
 * characters — so a key of any other shape saves, sends, and fails
 * verification on every submission. The configuration form refuses one, the
 * dashboard flags one already stored, and the queue and the drain job will
 * not send with one; all of them read the pattern from here.
 * config/module.config.php spells the route constraint out (config files stay
 * plain data), and WiringTest holds the two equal.
 */
final class IndexNowKey
{
    /** Unanchored, as a route constraint wants it. */
    public const PATTERN = '[A-Fa-f0-9]{8,128}';

    /** The anchored form, for a regular-expression validator. */
    public static function regex(): string
    {
        return '/\A' . self::PATTERN . '\z/';
    }

    public static function isValid(string $key): bool
    {
        return preg_match(self::regex(), $key) === 1;
    }
}
