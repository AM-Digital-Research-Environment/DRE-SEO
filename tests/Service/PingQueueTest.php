<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Job\PingSearchEngines;
use DRESeo\Service\PingOutcome;
use DRESeo\Service\PingQueue;
use DRESeo\Service\SettingsGate;
use DRESeo\Test\Double\InMemoryPingOutbox;
use DRESeo\Test\Double\MemoryLogger;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow dispatch policy — configured-ness, throttle window, batch
 * size, the bulk-change threshold — over an in-memory outbox. The durable outbox itself (dedupe, leases,
 * retries) is exercised against a real database in
 * tests/Integration/PingRepositoryTest.
 */
final class PingQueueTest extends TestCase
{
    private Settings $settings;
    private Dispatcher $dispatcher;
    private InMemoryPingOutbox $outbox;
    private MemoryLogger $logger;
    private PingQueue $queue;
    private int $now = 100000;

    /** @param array<string,mixed> $values */
    private function build(array $values = []): void
    {
        $this->settings = new Settings();
        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }
        $this->dispatcher = new Dispatcher();
        $this->outbox = new InMemoryPingOutbox();
        $this->logger = new MemoryLogger();
        $this->queue = new PingQueue(new SettingsGate($this->settings), $this->dispatcher, $this->outbox, $this->logger, fn (): int => $this->now);
    }

    protected function setUp(): void
    {
        $this->build([
            'dre_seo_ping_enabled' => '1',
            'dre_seo_indexnow_key' => 'deadbeefdeadbeef',
        ]);
    }

    public function testEnabledNeedsBothSwitchAndKey(): void
    {
        $this->assertTrue($this->queue->isEnabled());

        $this->build(['dre_seo_ping_enabled' => '1']);
        $this->assertFalse($this->queue->isEnabled(), 'no key');

        $this->build(['dre_seo_indexnow_key' => 'deadbeefdeadbeef']);
        $this->assertFalse($this->queue->isEnabled(), 'switched off');

        // A key of whitespace is not a key; one with surrounding spaces is.
        $this->build(['dre_seo_ping_enabled' => '1', 'dre_seo_indexnow_key' => '   ']);
        $this->assertFalse($this->queue->isEnabled());
        $this->build(['dre_seo_ping_enabled' => '1', 'dre_seo_indexnow_key' => ' deadbeefdeadbeef ']);
        $this->assertSame('deadbeefdeadbeef', $this->queue->key());

        // Nor is one IndexNow could never verify: /{key}.txt only serves hex.
        $this->build(['dre_seo_ping_enabled' => '1', 'dre_seo_indexnow_key' => 'not-a-hex-key']);
        $this->assertFalse($this->queue->isEnabled(), 'malformed key');
        $this->build(['dre_seo_ping_enabled' => '1', 'dre_seo_indexnow_key' => 'abc123']);
        $this->assertFalse($this->queue->isEnabled(), 'too short');

        // The checkbox as an older release or a script may have stored it.
        $this->build(['dre_seo_ping_enabled' => 1, 'dre_seo_indexnow_key' => 'deadbeefdeadbeef']);
        $this->assertTrue($this->queue->isEnabled());
    }

    public function testPushGoesToTheOutboxNotToSettings(): void
    {
        $this->queue->push('https://example.org/s/amira/item/1');

        $this->assertSame(['https://example.org/s/amira/item/1'], $this->outbox->pushed);
        $this->assertNull($this->settings->get('dre_seo_ping_pending'));
    }

    public function testClaimLeasesOneCappedBatchAndFinishPassesThrough(): void
    {
        $this->outbox->rows = array_map(static fn (int $i): array => ['url' => 'u' . $i], range(1, PingQueue::CAP + 5));

        $batch = $this->queue->claim();
        $this->assertSame(PingQueue::CAP, $this->outbox->lastLimit);
        $this->assertCount(PingQueue::CAP, $batch);

        $this->queue->finish($batch, PingOutcome::Refused);
        $this->assertSame([['rows' => $batch, 'outcome' => PingOutcome::Refused]], $this->outbox->finished);
    }

    /** One drain's worth of new URLs is editing, and goes out. */
    public function testAtTheThresholdNothingIsDiscarded(): void
    {
        $this->outbox->unsent = PingQueue::BULK_THRESHOLD;

        $this->assertSame(0, $this->queue->discardBulk());
        $this->assertSame(0, $this->outbox->discards);
        $this->assertSame(PingQueue::BULK_THRESHOLD, $this->outbox->unsent);
    }

    /** One more, and the drain takes it for a sync: all of it is acknowledged unsent. */
    public function testAboveTheThresholdEveryUnsentUrlIsDiscarded(): void
    {
        $this->outbox->unsent = PingQueue::BULK_THRESHOLD + 1;

        $this->assertSame(PingQueue::BULK_THRESHOLD + 1, $this->queue->discardBulk());
        $this->assertSame(1, $this->outbox->discards);
        $this->assertSame(0, $this->outbox->unsent);
    }

    public function testDispatchIsThrottledAndStamped(): void
    {
        $this->queue->dispatchIfDue();
        $this->assertSame([PingSearchEngines::class], array_column($this->dispatcher->dispatched, 'class'));
        $this->assertSame($this->now, $this->settings->get('dre_seo_ping_last'));

        // A burst of saves inside the window starts no second job.
        $this->now += 899;
        $this->queue->dispatchIfDue();
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    public function testWindowReopensAfterTheInterval(): void
    {
        $this->settings->set('dre_seo_ping_last', $this->now - 900);
        $this->queue->dispatchIfDue();
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    /** A failed dispatch must not burn the window, nor escape into the save; it is logged. */
    public function testFailedDispatchDoesNotStampTheWindowAndIsLogged(): void
    {
        $this->dispatcher->fail = true;
        $this->queue->dispatchIfDue();

        $this->assertSame([], $this->dispatcher->dispatched);
        $this->assertNull($this->settings->get('dre_seo_ping_last'));
        $this->assertCount(1, $this->logger->records['err'] ?? []);

        // The next save tries again.
        $this->dispatcher->fail = false;
        $this->queue->dispatchIfDue();
        $this->assertCount(1, $this->dispatcher->dispatched);
    }
}
