<?php
declare(strict_types=1);

namespace DRESeo\Job;

use DRESeo\Service\IndexNowKey;
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
    private const FLOOD_CAP = 200;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        if ((string) $settings->get('dre_seo_ping_enabled', '0') !== '1') {
            return;
        }
        $key = trim((string) $settings->get('dre_seo_indexnow_key', ''));
        if (!IndexNowKey::isValid($key)) {
            $logger->warn('DRESeo: IndexNow ping enabled but the configured key is invalid.');
            return;
        }

        $pending = $settings->get('dre_seo_ping_pending', []);
        $settings->set('dre_seo_ping_pending', []); // claim & clear the queue
        if (!is_array($pending) || $pending === []) {
            return;
        }

        $urls = array_values(array_unique(array_filter($pending)));
        if (count($urls) >= self::FLOOD_CAP) {
            $logger->info(sprintf(
                'DRESeo: skipped IndexNow ping for a bulk change (%d URLs); the sitemap covers discovery.',
                count($urls)
            ));
            return;
        }

        $first = $urls[0];
        $host = (string) (parse_url($first, PHP_URL_HOST) ?: '');
        $scheme = (string) (parse_url($first, PHP_URL_SCHEME) ?: 'https');
        if ($host === '') {
            return;
        }
        $keyLocation = $scheme . '://' . $host . '/' . $key . '.txt';

        $ok = $services->get(Pinger::class)->submitIndexNow($host, $key, $keyLocation, $urls);
        $logger->info(sprintf(
            'DRESeo: IndexNow ping %s for %d URL(s).',
            $ok ? 'accepted' : 'failed',
            count($urls)
        ));
    }
}
