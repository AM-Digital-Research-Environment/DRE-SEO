<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\IndexNowKey;
use DRESeo\Service\Pinger;
use PHPUnit\Framework\TestCase;

/** IndexNow key validation, and which IndexNow answers are retried. */
final class IndexNowKeyTest extends TestCase
{
    public function testIndexNowKeysAcceptOnly8To128HexadecimalCharacters(): void
    {
        $this->assertTrue(IndexNowKey::isValid('0123abcd'));
        $this->assertTrue(IndexNowKey::isValid(str_repeat('A', 128)));
        $this->assertTrue(!IndexNowKey::isValid('0123abc'));
        $this->assertTrue(!IndexNowKey::isValid(str_repeat('a', 129)));
        $this->assertTrue(!IndexNowKey::isValid('0123-abcd'));
        $this->assertTrue(!IndexNowKey::isValid("0123abcd\n"));
    }

    public function testOnlyTransientIndexNowFailuresAreRetried(): void
    {
        foreach ([200 => true, 202 => true] as $status => $accepted) {
            $this->assertSame($accepted, Pinger::accepted($status));
        }
        foreach ([0 => true, 408 => true, 429 => true, 500 => true, 503 => true, 400 => false, 403 => false, 422 => false] as $status => $retry) {
            $this->assertSame($retry, Pinger::retryable($status), "HTTP {$status}");
        }
    }
}
