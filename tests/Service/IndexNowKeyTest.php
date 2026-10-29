<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\IndexNowKey;
use PHPUnit\Framework\TestCase;

/**
 * IndexNow key validation: the one definition the form, the dashboard, the
 * queue and the job share. Which answers are retried is PingOutcomeTest's.
 */
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
        $this->assertTrue(!IndexNowKey::isValid(''));
    }

    public function testTheValidatorPatternIsTheRouteConstraintAnchored(): void
    {
        $this->assertSame('/\A[A-Fa-f0-9]{8,128}\z/', IndexNowKey::regex());
        $this->assertSame('/\A' . IndexNowKey::PATTERN . '\z/', IndexNowKey::regex());
    }
}
