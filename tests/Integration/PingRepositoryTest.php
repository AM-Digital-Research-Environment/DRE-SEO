<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use DRESeo\Service\PingOutcome;
use DRESeo\Service\PingRepository;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow outbox against a real DBAL connection (SQLite, in memory):
 * dedupe, leases, backoff, the attempt cap, and the versioned writes that keep
 * a newer edit from being acknowledged by an older submission.
 */
final class PingRepositoryTest extends TestCase
{
    private PingRepository $queue;

    protected function setUp(): void
    {
        $this->queue = new PingRepository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->queue->install();
    }

    public function testInstallIsSafeToRepeatAndUninstallDropsTheTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $queue->install();
        self::assertSame(['pending' => 1, 'failed' => 0], $queue->counts(), 'a second install keeps the queue');
        self::assertArrayHasKey('dre_seo_ping_due', $connection->getSchemaManager()->listTableIndexes('dre_seo_ping'));

        $queue->uninstall();
        self::assertFalse($connection->getSchemaManager()->tablesExist(['dre_seo_ping']));
        $queue->uninstall();
    }

    public function testAUrlIsQueuedOnceHoweverOftenItIsPushed(): void
    {
        $this->queue->push('https://example.test/item/1');
        $this->queue->push('https://example.test/item/1');
        $this->queue->push('https://example.test/item/2');

        self::assertSame(['pending' => 2, 'failed' => 0], $this->queue->counts());
    }

    /** IndexNow takes absolute URLs, and the job groups them by origin. */
    public function testAnythingButAnAbsoluteHttpUrlIsNotQueued(): void
    {
        foreach (['', '/s/amira/item/1', 'ftp://example.test/x', 'https://', "https://example.test/a b", 'javascript:alert(1)'] as $url) {
            $this->queue->push($url);
        }

        self::assertSame(['pending' => 0, 'failed' => 0], $this->queue->counts());
    }

    /**
     * A refusal (bad key, foreign host) would fail the same way again, so
     * the rows are not resent: they count as failed at once, survive in the
     * table, and come back only through retryFailed().
     */
    public function testRefusedRowsAreFailedAndReturnThroughRetryFailed(): void
    {
        $this->queue->push('https://example.test/item/1');
        $this->queue->push('https://example.test/item/2');
        $now = time() + 1;
        $claimed = $this->queue->claim(now: $now);
        self::assertCount(2, $claimed);

        $this->queue->finish($claimed, PingOutcome::Refused, $now);
        self::assertSame(['pending' => 0, 'failed' => 2], $this->queue->counts());
        self::assertFalse($this->queue->hasDue($now + 86400 * 2), 'a refused URL is never due again by itself');
        self::assertSame([], $this->queue->claim(now: $now + 86400 * 2));

        $this->queue->retryFailed();
        self::assertSame(['pending' => 2, 'failed' => 0], $this->queue->counts());
        $again = $this->queue->claim(now: $now);
        self::assertCount(2, $again);
        self::assertSame([0, 0], array_map('intval', array_column($again, 'attempts')), 'a fresh start, not one attempt left');
    }

    /** Retryable failures keep backing off until they too run out of attempts. */
    public function testRetriesExhaustAtTheCap(): void
    {
        $this->queue->push('https://example.test/item/1');
        $now = time() + 1;
        for ($attempt = 1; $attempt <= PingRepository::MAX_ATTEMPTS; $attempt++) {
            $claimed = $this->queue->claim(now: $now);
            self::assertCount(1, $claimed, 'attempt ' . $attempt);
            $this->queue->finish($claimed, PingOutcome::Retry, $now);
            $now += 86400;
        }
        self::assertSame([], $this->queue->claim(now: $now));
        self::assertSame(['pending' => 0, 'failed' => 1], $this->queue->counts());
    }

    /** A refused batch must not swallow a newer edit made while it was in flight. */
    public function testRefusalLeavesANewerEditPending(): void
    {
        $this->queue->push('https://example.test/item/1');
        $claimed = $this->queue->claim();
        $this->queue->push('https://example.test/item/1');
        $this->queue->finish($claimed, PingOutcome::Refused);
        self::assertSame(['pending' => 1, 'failed' => 0], $this->queue->counts());
    }

    public function testConcurrentEditSurvivesAcknowledgement(): void
    {
        $this->queue->push('https://example.test/item/1');
        $old = $this->queue->claim();
        self::assertCount(1, $old);
        self::assertSame([], $this->queue->claim(), 'a leased URL is not leased twice');
        $this->queue->push('https://example.test/item/1');
        $this->queue->finish($old, PingOutcome::Accepted);
        self::assertSame(1, $this->queue->counts()['pending']);
        $this->queue->finish($this->queue->claim(), PingOutcome::Accepted);
        self::assertSame(0, $this->queue->counts()['pending']);
    }

    public function testHasDueIgnoresLeasedAndBackingOffUrls(): void
    {
        self::assertFalse($this->queue->hasDue());
        $this->queue->push('https://example.test/item/1');
        $now = time() + 1;
        self::assertTrue($this->queue->hasDue($now));
        $claimed = $this->queue->claim(now: $now);
        self::assertFalse($this->queue->hasDue($now), 'a leased URL is not due');
        $this->queue->finish($claimed, PingOutcome::Retry, $now);
        self::assertFalse($this->queue->hasDue($now + 1), 'a failed URL waits out its backoff');
        self::assertTrue($this->queue->hasDue($now + 121));
    }

    public function testFailureRetriesAndAbandonedLeaseExpires(): void
    {
        $this->queue->push('https://example.test/item/1');
        $now = time() + 1;
        $first = $this->queue->claim(now: $now);
        self::assertCount(1, $first);
        self::assertSame([], $this->queue->claim(now: $now + 599));
        $retry = $this->queue->claim(now: $now + 601);
        self::assertCount(1, $retry, 'the dead job’s lease ran out');
        $this->queue->finish($first, PingOutcome::Accepted);
        self::assertSame(1, $this->queue->counts()['pending'], 'the dead job no longer holds the lease it acknowledges');
        $this->queue->finish($retry, PingOutcome::Retry, $now + 601);
        self::assertSame([], $this->queue->claim(now: $now + 602));
        self::assertCount(1, $this->queue->claim(now: $now + 722));
    }

    public function testAClaimLeasesAtMostTwoHundredUrls(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            $this->queue->push('https://example.test/item/' . $i);
        }
        $now = time() + 1;

        self::assertCount(200, $this->queue->claim(500, $now));
        self::assertCount(5, $this->queue->claim(500, $now));
    }
}
