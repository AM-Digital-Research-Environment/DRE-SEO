<?php
declare(strict_types=1);

namespace DRESeo\Job;

use DRESeo\Service\IndexNowKey;
use DRESeo\Service\PingQueue;
use DRESeo\Service\Pinger;
use Omeka\Job\AbstractJob;

/**
 * Drains the pending-URL queue (filled by Module::handleContentChange when
 * public items/pages change) and submits it to IndexNow. Runs asynchronously so
 * the saving request is never blocked by the network call.
 *
 * If the queue is at the flood cap the change almost certainly came from a bulk
 * sync, so the ping is skipped — those URLs are discovered through the sitemap
 * instead, and IndexNow is reserved for genuine incremental edits.
 */
class PingSearchEngines extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        $queue = $services->get(PingQueue::class);
        $pending = $queue->claim();
        $complete = true;

        try {
            if ((string) $settings->get('dre_seo_ping_enabled', '0') !== '1') {
                return;
            }
            $key = trim((string) $settings->get('dre_seo_indexnow_key', ''));
            if (!IndexNowKey::isValid($key)) {
                $logger->warn('DRESeo: IndexNow ping enabled but the configured key is invalid.');
                return;
            }
            if ($pending === []) {
                return;
            }

            if (count($pending) >= PingQueue::QUEUE_CAP) {
                $logger->info(sprintf(
                    'DRESeo: skipped IndexNow ping for a bulk change (%d URLs); the sitemap covers discovery.',
                    count($pending)
                ));
                return;
            }

            $first = $pending[0];
            $host = (string) (parse_url($first, PHP_URL_HOST) ?: '');
            $scheme = (string) (parse_url($first, PHP_URL_SCHEME) ?: 'https');
            if ($host === '') {
                return;
            }
            $keyLocation = $scheme . '://' . $host . '/' . $key . '.txt';

            try {
                $ok = $services->get(Pinger::class)->submitIndexNow($host, $key, $keyLocation, $pending);
            } catch (\Throwable $e) {
                $queue->requeue($pending);
                $complete = false;
                $logger->err('DRESeo: unexpected IndexNow submission failure; retained the batch.', [
                    'exception' => $e,
                ]);
                return;
            }
            if (!$ok) {
                $queue->requeue($pending);
                $complete = false;
            }
            $logger->info(sprintf(
                'DRESeo: IndexNow ping %s for %d URL(s).',
                $ok ? 'accepted' : 'failed; retained for retry',
                count($pending)
            ));
        } finally {
            if ($complete) {
                $queue->complete();
            }
        }
    }
}
