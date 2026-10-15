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

/**
 * The settings table two requests share, behind the MySQL connection the queue
 * locks and writes through. Each request keeps its own (stale) Settings cache,
 * as Omeka's does.
 */
final class SharedSettingsTable extends Doctrine\DBAL\Connection
{
    /** @var array<string,string> id => JSON value */
    public array $rows = [];

    /** @var string[] */
    public array $locks = [];

    public function getDatabasePlatform(): object
    {
        if (!class_exists('Doctrine\DBAL\Platforms\MySQLPlatform')) {
            eval('namespace Doctrine\DBAL\Platforms; class MySQLPlatform {}');
        }
        return new Doctrine\DBAL\Platforms\MySQLPlatform();
    }

    public function fetchOne(string $query, array $params = []): mixed
    {
        if (str_starts_with($query, 'SELECT GET_LOCK')) {
            $this->locks[] = 'lock';
            return 1;
        }
        if (str_starts_with($query, 'SELECT RELEASE_LOCK')) {
            $this->locks[] = 'release';
            return 1;
        }
        return $this->rows[$params[0]] ?? false;
    }

    public function executeStatement(string $query, array $params = []): int
    {
        $this->rows[$params[0]] = $params[1];
        return 1;
    }
}

test('concurrent requests do not lose each other\'s URLs', function (): void {
    $table = new SharedSettingsTable();
    $dispatcher = new Dispatcher();
    // Two requests, each with its own Settings cache, saving at once.
    $first = new PingQueue(new Settings(), $dispatcher, new MemoryLogger(), static fn (): int => 1000, $table);
    $second = new PingQueue(new Settings(), $dispatcher, new MemoryLogger(), static fn (): int => 1000, $table);

    $first->enqueue('https://example.test/s/amira/item/1');
    $second->enqueue('https://example.test/s/amira/item/2');

    assertSameValue(1, count($dispatcher->dispatched), 'one job for both');
    assertSameValue(
        ['https://example.test/s/amira/item/1', 'https://example.test/s/amira/item/2'],
        $first->claim()
    );
    assertSameValue('[]', $table->rows['dre_seo_ping_pending']);
    assertSameValue(['lock', 'release', 'lock', 'release', 'lock', 'release'], $table->locks, 'every write under the lock');
});
