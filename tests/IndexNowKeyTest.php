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
