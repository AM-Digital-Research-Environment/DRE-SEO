<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\Pinger;
use DRESeo\Service\PingOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How an IndexNow answer settles its batch. The statuses are the ones the
 * protocol documents (200/202 accepted; 400, 403, 422 refused; 429 too many
 * requests) plus a transport failure, which the Pinger reports as NOT_SENT.
 */
final class PingOutcomeTest extends TestCase
{
    /** @return array<string,array{int,PingOutcome}> */
    public static function statuses(): array
    {
        return [
            'OK' => [200, PingOutcome::Accepted],
            'Accepted, key validation pending' => [202, PingOutcome::Accepted],
            'no response' => [Pinger::NOT_SENT, PingOutcome::Retry],
            'request timeout' => [408, PingOutcome::Retry],
            'too many requests' => [429, PingOutcome::Retry],
            'server error' => [500, PingOutcome::Retry],
            'service unavailable' => [503, PingOutcome::Retry],
            'bad request' => [400, PingOutcome::Refused],
            'key not valid' => [403, PingOutcome::Refused],
            'URLs not of this host' => [422, PingOutcome::Refused],
            'redirect left unfollowed' => [301, PingOutcome::Refused],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusSettlesTheBatch(int $status, PingOutcome $expected): void
    {
        $this->assertSame($expected, PingOutcome::fromStatus($status));
    }
}
