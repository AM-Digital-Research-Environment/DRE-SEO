<?php
declare(strict_types=1);

use DRESeo\Service\IndexNowKey;

require_once __DIR__ . '/../src/Service/IndexNowKey.php';

test('IndexNow keys accept only 8 to 128 hexadecimal characters', function (): void {
    assertTrue(IndexNowKey::isValid('0123abcd'));
    assertTrue(IndexNowKey::isValid(str_repeat('A', 128)));
    assertTrue(!IndexNowKey::isValid('0123abc'));
    assertTrue(!IndexNowKey::isValid(str_repeat('a', 129)));
    assertTrue(!IndexNowKey::isValid('0123-abcd'));
    assertTrue(!IndexNowKey::isValid("0123abcd\n"));
});

test('only transient IndexNow failures are retried', function (): void {
    require_once __DIR__ . '/../src/Service/Pinger.php';
    foreach ([200 => true, 202 => true] as $status => $accepted) {
        assertSameValue($accepted, DRESeo\Service\Pinger::accepted($status));
    }
    foreach ([0 => true, 408 => true, 429 => true, 500 => true, 503 => true, 400 => false, 403 => false, 422 => false] as $status => $retry) {
        assertSameValue($retry, DRESeo\Service\Pinger::retryable($status), "HTTP {$status}");
    }
});
