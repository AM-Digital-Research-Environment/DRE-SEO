<?php
declare(strict_types=1);

namespace DRESeo\Service;

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
 */
final class PingQueue
{
    public const QUEUE_CAP = 200;

    private const PENDING_KEY = 'dre_seo_ping_pending';
    private const QUEUED_AT_KEY = 'dre_seo_ping_job_queued';
    private const STALE_AFTER = 900;

    public function __construct(
        private readonly Settings $settings,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function enqueue(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $pending = $this->pending();
        if (count($pending) < self::QUEUE_CAP && !in_array($url, $pending, true)) {
            $pending[] = $url;
            $this->settings->set(self::PENDING_KEY, $pending);
        }
        $this->scheduleIfNeeded($pending);
    }

    /** @return string[] */
    public function claim(): array
    {
        $pending = $this->pending();
        $this->settings->set(self::PENDING_KEY, []);
        return $pending;
    }

    /**
     * Release the single-flight lock and dispatch one follow-up job when edits
     * arrived after the current job claimed its batch.
     */
    public function complete(): void
    {
        $this->settings->set(self::QUEUED_AT_KEY, 0);
        $this->scheduleIfNeeded($this->pending());
    }

    /**
     * Put a failed batch back without immediately retrying in a tight loop.
     * The next content edit (or a stale-lock recovery) schedules another job.
     *
     * @param string[] $urls
     */
    public function requeue(array $urls): void
    {
        $pending = array_values(array_unique(array_merge($urls, $this->pending())));
        $this->settings->set(self::PENDING_KEY, array_slice($pending, 0, self::QUEUE_CAP));
        $this->settings->set(self::QUEUED_AT_KEY, 0);
    }

    /** @return string[] */
    private function pending(): array
    {
        $pending = $this->settings->get(self::PENDING_KEY, []);
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
        $queuedAt = (int) $this->settings->get(self::QUEUED_AT_KEY, 0);
        if ($queuedAt > 0 && $queuedAt >= $now - self::STALE_AFTER) {
            return;
        }

        $this->settings->set(self::QUEUED_AT_KEY, $now);
        try {
            $this->dispatcher->dispatch(PingSearchEngines::class);
        } catch (\Throwable $e) {
            $this->settings->set(self::QUEUED_AT_KEY, 0);
            $this->logger->err('DRESeo: failed to dispatch the IndexNow job.', [
                'exception' => $e,
            ]);
        }
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
}
