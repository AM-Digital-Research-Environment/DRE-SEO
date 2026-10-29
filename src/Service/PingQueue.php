<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Job\PingSearchEngines;
use Laminas\Log\LoggerInterface;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;

/**
 * IndexNow dispatch policy over the durable outbox: whether pinging is
 * configured, how often a save may start a drain job, the batch size a job
 * leases, and when a drain takes its URLs for a bulk change that is left to
 * the sitemap. The outbox itself (dedupe, leases, retries) is
 * {@see PingRepository}; the five-minute cron drains it independently of
 * saves (scripts/drain-indexnow.php), and the save-triggered job is the
 * fallback for an installation without the cron.
 */
final class PingQueue
{
    /** URLs leased per job. */
    public const CAP = 200;

    /**
     * More URLs waiting for their first submission than this, and a drain
     * takes them for a bulk change rather than editing, and sends none.
     *
     * Drains come five minutes apart (the cron) or at most a quarter hour
     * apart (a save's dispatch). Editors working by hand do not change more
     * public records than one drain sends in that time; a MongoDB2OmekaS
     * sync re-saves thousands through the API, most of them unchanged.
     * IndexNow is for URLs whose content changed, and every record a sync
     * touches is in the sitemap, whose cache that same save cleared. The
     * figure is one drain's batch, which is also the flood cap at which 0.15
     * and earlier skipped a ping.
     */
    public const BULK_THRESHOLD = self::CAP;

    /** How often (seconds) a save may dispatch a drain job. */
    private const DISPATCH_INTERVAL = 900;

    private const LAST_DISPATCH = 'dre_seo_ping_last';

    public function __construct(
        private readonly Settings $settings,
        private readonly Dispatcher $dispatcher,
        private readonly PingOutboxInterface $outbox,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Pinging is configured: switched on *and* carrying a well-formed
     * ownership key. A malformed key can never be verified (its /{key}.txt is
     * not served), so queueing under one would only fill the outbox with work
     * that fails; the dashboard flags such a key instead.
     */
    public function isEnabled(): bool
    {
        return SettingFlag::isOn($this->settings, 'dre_seo_ping_enabled') && IndexNowKey::isValid($this->key());
    }

    /** The IndexNow ownership key, or '' when unset. */
    public function key(): string
    {
        return trim((string) ($this->settings->get('dre_seo_indexnow_key', '') ?? ''));
    }

    /** Queue a URL for submission. */
    public function push(string $url): void
    {
        $this->outbox->push($url);
    }

    /**
     * Dispatch a drain job if the throttle window has elapsed, so a burst of
     * saves starts one job rather than one each. The window is stamped only
     * after a successful dispatch, so a failed one does not burn it; the
     * failure is logged, not thrown, because SEO bookkeeping must never break
     * the save that triggered it — and the URL is in the outbox either way.
     */
    public function dispatchIfDue(): void
    {
        $now = $this->now();
        if ($now - (int) $this->settings->get(self::LAST_DISPATCH, 0) < self::DISPATCH_INTERVAL) {
            return;
        }
        try {
            $this->dispatcher->dispatch(PingSearchEngines::class);
            $this->settings->set(self::LAST_DISPATCH, $now);
        } catch (\Throwable $e) {
            $this->logger->err('DRESeo: failed to dispatch the IndexNow job.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Settle a bulk change without sending it: when more URLs wait for their
     * first submission than {@see BULK_THRESHOLD}, acknowledge them all as
     * handled. URLs being retried, or re-queued after failing, were each
     * submitted before as part of an incremental batch, so they are neither
     * counted nor removed, and go out as usual.
     *
     * @return int how many URLs were discarded; 0 when the change is not bulk
     */
    public function discardBulk(): int
    {
        if ($this->outbox->countUnsent() <= self::BULK_THRESHOLD) {
            return 0;
        }
        return $this->outbox->discardUnsent();
    }

    /**
     * Lease the next batch.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim(): array
    {
        return $this->outbox->claim(self::CAP);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function finish(array $rows, PingOutcome $outcome): void
    {
        $this->outbox->finish($rows, $outcome);
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
}
