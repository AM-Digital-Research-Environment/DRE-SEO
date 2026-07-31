<?php
declare(strict_types=1);

namespace DRESeo\Service;

/** Shared validation for the configured IndexNow ownership key. */
final class IndexNowKey
{
    public static function isValid(string $key): bool
    {
        return preg_match('/\A[A-Fa-f0-9]{8,128}\z/D', $key) === 1;
    }
}
