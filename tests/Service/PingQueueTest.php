<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Job\PingSearchEngines;
use DRESeo\Service\PingQueue;
use DRESeo\Test\Double\MemoryLogger;
use DRESeo\Test\Double\SharedSettingsTable;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow queue: coalescing, retries, stale-lock recovery, and two
 * requests writing the shared settings row at once.
 */
final class PingQueueTest extends TestCase
{
    public function testIndexNowQueueCoalescesEditsAndSchedulesOneFollowUpBatch(): void
    {
        $settings = new Settings();
        $dispatcher = new Dispatcher();
        $logger = new MemoryLogger();
        $now = 1000;
        $queue = new PingQueue($settings, $dispatcher, $logger, static fn (): int => $now);

        $queue->enqueue('https://example.test/item/1');
        $queue->enqueue('https://example.test/item/2');
        $this->assertSame(1, count($dispatcher->dispatched));
        $this->assertSame(PingSearchEngines::class, $dispatcher->dispatched[0]['class']);
        $this->assertSame([
            'https://example.test/item/1',
            'https://example.test/item/2',
        ], $queue->claim());

        $queue->enqueue('https://example.test/item/3');
        $this->assertSame(1, count($dispatcher->dispatched));
        $queue->complete();
        $this->assertSame(2, count($dispatcher->dispatched));
        $this->assertSame(['https://example.test/item/3'], $queue->claim());
    }

    public function testIndexNowQueueRetainsAFailedBatchUntilALaterEditRetriesIt(): void
    {
        $settings = new Settings();
        $dispatcher = new Dispatcher();
        $queue = new PingQueue($settings, $dispatcher, new MemoryLogger(), static fn (): int => 1000);

        $queue->enqueue('https://example.test/item/1');
        $batch = $queue->claim();
        $queue->requeue($batch);
        $queue->enqueue('https://example.test/item/2');

        $this->assertSame(2, count($dispatcher->dispatched));
        $this->assertSame([
            'https://example.test/item/1',
            'https://example.test/item/2',
        ], $queue->claim());
    }

    public function testIndexNowQueueRecoversStaleLocksAndLogsDispatchFailures(): void
    {
        $settings = new Settings();
        $settings->set('dre_seo_ping_pending', ['https://example.test/item/1']);
        $settings->set('dre_seo_ping_job_queued', 1000);
        $dispatcher = new Dispatcher();
        $logger = new MemoryLogger();
        $queue = new PingQueue($settings, $dispatcher, $logger, static fn (): int => 2000);

        $queue->enqueue('https://example.test/item/2');
        $this->assertSame(1, count($dispatcher->dispatched));

        $queue->claim();
        $queue->complete();
        $dispatcher->fail = true;
        $queue->enqueue('https://example.test/item/3');
        $this->assertSame(1, count($logger->records['err'] ?? []));
        $this->assertSame(0, $settings->get('dre_seo_ping_job_queued'));
    }

    public function testConcurrentRequestsDoNotLoseEachOthersURLs(): void
    {
        $table = new SharedSettingsTable();
        $dispatcher = new Dispatcher();
        // Two requests, each with its own Settings cache, saving at once.
        $first = new PingQueue(new Settings(), $dispatcher, new MemoryLogger(), static fn (): int => 1000, $table);
        $second = new PingQueue(new Settings(), $dispatcher, new MemoryLogger(), static fn (): int => 1000, $table);

        $first->enqueue('https://example.test/s/amira/item/1');
        $second->enqueue('https://example.test/s/amira/item/2');

        $this->assertSame(1, count($dispatcher->dispatched), 'one job for both');
        $this->assertSame(
            ['https://example.test/s/amira/item/1', 'https://example.test/s/amira/item/2'],
            $first->claim()
        );
        $this->assertSame('[]', $table->rows['dre_seo_ping_pending']);
        $this->assertSame(['lock', 'release', 'lock', 'release', 'lock', 'release'], $table->locks, 'every write under the lock');
    }
}
