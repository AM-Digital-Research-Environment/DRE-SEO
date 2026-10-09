<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * The site whose URLs are canonical outside a site request — the sitemap and
 * robots.txt at the host root, the /cite downloads, the IndexNow pings and the
 * admin dashboard all speak for one site: Omeka's configured default site, else
 * the first site. Resolved once per request.
 */
final class DefaultSite
{
    private ?SiteRepresentation $site = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function get(): ?SiteRepresentation
    {
        if ($this->resolved) {
            return $this->site;
        }
        $this->resolved = true;

        $defaultSiteId = (int) $this->settings->get('default_site');
        if ($defaultSiteId) {
            try {
                $site = $this->api->read('sites', $defaultSiteId)->getContent();
                if ($site instanceof SiteRepresentation) {
                    return $this->site = $site;
                }
            } catch (\Throwable $e) {
                // fall through to the first site
            }
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
        } catch (\Throwable $e) {
            return null;
        }
        $site = $sites[0] ?? null;

        return $this->site = $site instanceof SiteRepresentation ? $site : null;
    }
}
