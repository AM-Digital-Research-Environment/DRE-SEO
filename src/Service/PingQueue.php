<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Doctrine\DBAL\Connection;
use DRESeo\Job\PingSearchEngines;
use Laminas\Log\LoggerInterface;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;

/**
 * Single-flight queue for IndexNow submissions.
 *
 * The first edit dispatches a background job. Further edits only append to the
 * shared queue while that job is queued or running, so bulk API activity cannot
 * create one Omeka job per resource. A timestamp lock recovers automatically if
 * a worker dies before releasing it.
 *
 * The queue lives in two settings rows. Every change to them is a
 * read-modify-write, and two requests saving at once would each read the same
 * list and the second write would drop the first one's URL. Omeka's Settings
 * service makes that worse: it caches every row for the whole request and
 * skips a write that equals its cached value. So, given the database
 * connection, the queue reads and writes its rows directly, inside a MySQL
 * named lock (GET_LOCK). Without a connection (tests) or on another database it
 * falls back to the Settings service, unlocked, as before.
 */
final class PingQueue
{
    public const QUEUE_CAP = 200;

    private const PENDING_KEY = 'dre_seo_ping_pending';
    private const QUEUED_AT_KEY = 'dre_seo_ping_job_queued';
    private const STALE_AFTER = 900;

    /** The MySQL named lock that serialises queue writes, and how long to wait for it. */
    private const LOCK_NAME = 'dre_seo_ping_queue';
    private const LOCK_TIMEOUT = 5;

    private ?bool $lockable = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null,
        private readonly ?Connection $connection = null,
    ) {
    }

    public function enqueue(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $this->locked(function () use ($url): void {
            $pending = $this->pending();
            if (count($pending) < self::QUEUE_CAP && !in_array($url, $pending, true)) {
                $pending[] = $url;
                $this->write(self::PENDING_KEY, $pending);
            }
            $this->scheduleIfNeeded($pending);
        });
    }

    /** @return string[] */
    public function claim(): array
    {
        return $this->locked(function (): array {
            $pending = $this->pending();
            $this->write(self::PENDING_KEY, []);
            return $pending;
        });
    }

    /**
     * Release the single-flight lock and dispatch one follow-up job when edits
     * arrived after the current job claimed its batch.
     */
    public function complete(): void
    {
        $this->locked(function (): void {
            $this->write(self::QUEUED_AT_KEY, 0);
            $this->scheduleIfNeeded($this->pending());
        });
    }

    /**
     * Put a failed batch back without immediately retrying in a tight loop.
     * The next content edit (or a stale-lock recovery) schedules another job.
     *
     * @param string[] $urls
     */
    public function requeue(array $urls): void
    {
        $this->locked(function () use ($urls): void {
            $pending = array_values(array_unique(array_merge($urls, $this->pending())));
            $this->write(self::PENDING_KEY, array_slice($pending, 0, self::QUEUE_CAP));
            $this->write(self::QUEUED_AT_KEY, 0);
        });
    }

    /** @return string[] */
    private function pending(): array
    {
        $pending = $this->read(self::PENDING_KEY, []);
        if (!is_array($pending)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            $pending,
            static fn ($url): bool => is_string($url) && trim($url) !== ''
        )));
    }

    /** @param string[] $pending */
    private function scheduleIfNeeded(array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $now = $this->now();
        $queuedAt = (int) $this->read(self::QUEUED_AT_KEY, 0);
        if ($queuedAt > 0 && $queuedAt >= $now - self::STALE_AFTER) {
            return;
        }

        $this->write(self::QUEUED_AT_KEY, $now);
        try {
            $this->dispatcher->dispatch(PingSearchEngines::class);
        } catch (\Throwable $e) {
            $this->write(self::QUEUED_AT_KEY, 0);
            $this->logger->err('DRESeo: failed to dispatch the IndexNow job.', [
                'exception' => $e,
            ]);
        }
    }

    // ─── Storage ────────────────────────────────────────────────────────────

    /**
     * Run $work holding the queue lock. A lock that cannot be had in time is
     * logged and the work runs anyway: a lost URL is a slower crawl, a lost
     * save would be worse.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $locked = false;
        if ($this->usesDatabase()) {
            try {
                $locked = (int) $this->connection->fetchOne(
                    'SELECT GET_LOCK(?, ?)',
                    [self::LOCK_NAME, self::LOCK_TIMEOUT]
                ) === 1;
            } catch (\Throwable $e) {
                $locked = false;
            }
            if (!$locked) {
                $this->logger->warn('DRESeo: could not lock the IndexNow queue; writing without the lock.');
            }
        }
        try {
            return $work();
        } finally {
            if ($locked) {
                try {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
                } catch (\Throwable $e) {
                    // The lock is released when the connection closes anyway.
                }
            }
        }
    }

    private function read(string $key, mixed $default): mixed
    {
        if (!$this->usesDatabase()) {
            return $this->settings->get($key, $default);
        }
        try {
            $raw = $this->connection->fetchOne('SELECT value FROM setting WHERE id = ?', [$key]);
        } catch (\Throwable $e) {
            return $this->settings->get($key, $default);
        }
        if (!is_string($raw)) {
            return $default;
        }
        $value = json_decode($raw, true);
        return $value ?? $default;
    }

    private function write(string $key, mixed $value): void
    {
        if (!$this->usesDatabase()) {
            $this->settings->set($key, $value);
            return;
        }
        try {
            // Settings rows hold JSON, as Omeka's json_array type writes them.
            $this->connection->executeStatement(
                'INSERT INTO setting (id, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            $this->settings->set($key, $value);
        }
    }

    /** Whether the queue can use the database directly: a MySQL or MariaDB connection. */
    private function usesDatabase(): bool
    {
        if ($this->lockable === null) {
            $this->lockable = false;
            if ($this->connection !== null) {
                try {
                    $this->lockable = $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform;
                } catch (\Throwable $e) {
                    $this->lockable = false;
                }
            }
        }
        return $this->lockable;
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
}
