<?php
declare(strict_types=1);

use DRESeo\Job\PingSearchEngines;
use DRESeo\Service\PingQueue;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;

require_once __DIR__ . '/../src/Service/PingQueue.php';

test('IndexNow queue coalesces edits and schedules one follow-up batch', function (): void {
    $settings = new Settings();
    $dispatcher = new Dispatcher();
    $logger = new MemoryLogger();
    $now = 1000;
    $queue = new PingQueue($settings, $dispatcher, $logger, static fn (): int => $now);

    $queue->enqueue('https://example.test/item/1');
    $queue->enqueue('https://example.test/item/2');
    assertSameValue(1, count($dispatcher->dispatched));
    assertSameValue(PingSearchEngines::class, $dispatcher->dispatched[0]['class']);
    assertSameValue([
        'https://example.test/item/1',
        'https://example.test/item/2',
    ], $queue->claim());

    $queue->enqueue('https://example.test/item/3');
    assertSameValue(1, count($dispatcher->dispatched));
    $queue->complete();
    assertSameValue(2, count($dispatcher->dispatched));
    assertSameValue(['https://example.test/item/3'], $queue->claim());
});

test('IndexNow queue retains a failed batch until a later edit retries it', function (): void {
    $settings = new Settings();
    $dispatcher = new Dispatcher();
    $queue = new PingQueue($settings, $dispatcher, new MemoryLogger(), static fn (): int => 1000);

    $queue->enqueue('https://example.test/item/1');
    $batch = $queue->claim();
    $queue->requeue($batch);
    $queue->enqueue('https://example.test/item/2');

    assertSameValue(2, count($dispatcher->dispatched));
    assertSameValue([
        'https://example.test/item/1',
        'https://example.test/item/2',
    ], $queue->claim());
});

test('IndexNow queue recovers stale locks and logs dispatch failures', function (): void {
    $settings = new Settings();
    $settings->set('dre_seo_ping_pending', ['https://example.test/item/1']);
    $settings->set('dre_seo_ping_job_queued', 1000);
    $dispatcher = new Dispatcher();
    $logger = new MemoryLogger();
    $queue = new PingQueue($settings, $dispatcher, $logger, static fn (): int => 2000);

    $queue->enqueue('https://example.test/item/2');
    assertSameValue(1, count($dispatcher->dispatched));

    $queue->claim();
    $queue->complete();
    $dispatcher->fail = true;
    $queue->enqueue('https://example.test/item/3');
    assertSameValue(1, count($logger->records['err'] ?? []));
    assertSameValue(0, $settings->get('dre_seo_ping_job_queued'));
});
