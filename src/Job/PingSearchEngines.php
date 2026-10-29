<?php
declare(strict_types=1);

namespace DRESeo\Job;

use DRESeo\Service\IndexNowKey;
use DRESeo\Service\Pinger;
use DRESeo\Service\PingOutcome;
use DRESeo\Service\PingQueue;
use DRESeo\Service\SettingFlag;
use Omeka\Job\AbstractJob;

/**
 * Drains the IndexNow outbox (filled by Module::handleContentChange when
 * public items, item sets and pages change) and submits it to IndexNow. Runs
 * as a job — from the five-minute cron, or dispatched by a save — so the
 * saving request is never blocked by the network call.
 *
 * When more URLs wait for their first submission than one run sends
 * ({@see PingQueue::BULK_THRESHOLD}), the change almost certainly came from a
 * bulk sync, so they are acknowledged without a ping — those URLs are
 * discovered through the sitemap instead, and IndexNow is reserved for
 * genuine incremental edits. Retries and re-queued failures still go out.
 *
 * One run leases one batch of up to {@see PingQueue::CAP} URLs and submits it
 * per origin, since IndexNow takes one host per request. Leases survive worker
 * failures: rows a dead job leased come back when the lease runs out. Each
 * origin's batch is settled by what IndexNow answered ({@see PingOutcome}):
 * accepted rows are acknowledged, transient failures retried with backoff,
 * and refusals parked as failed so they are not resent until an administrator
 * has fixed the cause.
 */
class PingSearchEngines extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $queue = $services->get(PingQueue::class);

        if (!SettingFlag::isOn($services->get('Omeka\Settings'), 'dre_seo_ping_enabled')) {
            return;
        }
        $key = $queue->key();
        if (!IndexNowKey::isValid($key)) {
            // Nothing is claimed, so the queued URLs keep their attempts and
            // go out once a valid key is saved.
            $logger->warn('DRESeo: IndexNow ping enabled but no valid key is configured (8–128 hexadecimal characters).');
            return;
        }

        $skipped = $queue->discardBulk();
        if ($skipped > 0) {
            $logger->info(
                'DRESeo: skipped IndexNow for a bulk change of {count} URL(s), most likely a sync; the sitemap covers their discovery.',
                ['count' => $skipped]
            );
        }

        $batch = $queue->claim();
        if ($batch === []) {
            return;
        }
        $groups = [];
        foreach ($batch as $row) {
            $groups[self::origin((string) $row['url'])][] = $row;
        }
        foreach ($groups as $origin => $rows) {
            $origin = (string) $origin;
            $host = (string) parse_url($origin, PHP_URL_HOST);
            $keyLocation = $origin . '/' . $key . '.txt';
            $context = ['count' => count($rows), 'host' => $host];
            try {
                $status = $services->get(Pinger::class)->submitIndexNow(
                    $host,
                    $key,
                    $keyLocation,
                    array_column($rows, 'url')
                );
            } catch (\Throwable $error) {
                $status = Pinger::NOT_SENT;
                $logger->err(
                    'DRESeo: IndexNow transport failed for {host}: {error}',
                    $context + ['exception' => $error, 'error' => $error->getMessage()]
                );
            }
            $outcome = PingOutcome::fromStatus($status);
            $queue->finish($rows, $outcome);
            $context['status'] = $status;
            match ($outcome) {
                PingOutcome::Accepted => $logger->info(
                    'DRESeo: IndexNow accepted {count} URL(s) for {host} (HTTP {status}).',
                    $context
                ),
                PingOutcome::Retry => $logger->info(
                    'DRESeo: IndexNow submission of {count} URL(s) for {host} failed (HTTP {status}, 0 = no response); they will be retried with backoff.',
                    $context
                ),
                PingOutcome::Refused => $logger->warn(
                    'DRESeo: IndexNow refused {count} URL(s) for {host} with HTTP {status}. They will not be resent: check that the key is valid and served at {keyLocation}, and that the URLs belong to {host}; then re-queue them with scripts/drain-indexnow.php --retry-failed.',
                    $context + ['keyLocation' => $keyLocation]
                ),
            };
        }
    }

    /** A URL's origin, `scheme://host[:port]`: where its key file is served. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
