<?php
declare(strict_types=1);

namespace DRESeo\Controller;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationKindMap;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * /cite/:id/:format — single-item citation downloads (BibTeX, RIS, CSL-JSON).
 *
 * Reuses the {@see CitationData} mapping + {@see CitationExport} serialisers that
 * back the theme's "Cite this record" rail, so a reader can save a record
 * straight into Zotero / Mendeley / EndNote / a LaTeX bibliography. Only public,
 * citable items resolve — authority records (person / place / organisation /
 * project / research section) and non-public items 404.
 *
 * The canonical URL baked into the export is the *default site's* item page, so
 * a downloaded citation points at the same address regardless of which site the
 * reader came from.
 */
class CitationController extends AbstractActionController
{
    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationExport $citationExport,
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->enabled()) {
            return $this->notFound();
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $format = (string) $this->params()->fromRoute('format', '');
        if ($id <= 0 || !isset(CitationExport::FORMATS[$format])) {
            return $this->notFound();
        }

        $item = $this->resolveItem($id);
        if ($item === null) {
            return $this->notFound();
        }

        $record = $this->citationData->build($item, $this->itemUrl($item));
        if ($record === null) {
            return $this->notFound(); // authority record — not a citable work
        }

        $body = $this->citationExport->serialize($record, $format);
        if ($body === null) {
            return $this->notFound();
        }

        [, $contentType] = CitationExport::FORMATS[$format];

        return $this->fileResponse($body, $contentType, $this->citationExport->filename($record, $format));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveItem(int $id): ?ItemRepresentation
    {
        try {
            $item = $this->api->read('items', $id)->getContent();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$item instanceof ItemRepresentation || !$item->isPublic()) {
            return null;
        }

        return $this->citationData->isCitable(CitationKindMap::templateId($item)) ? $item : null;
    }

    /** The default site's public page URL — the citation's stable canonical. */
    private function itemUrl(ItemRepresentation $item): ?string
    {
        $site = $this->resolveSite();
        if (!$site) {
            return null;
        }
        try {
            $url = (string) $item->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }

        return $url !== '' ? $url : null;
    }

    /** The configured default site, else the first site. */
    private function resolveSite(): ?SiteRepresentation
    {
        $defaultSiteId = (int) $this->settings->get('default_site');
        if ($defaultSiteId) {
            try {
                return $this->api->read('sites', $defaultSiteId)->getContent();
            } catch (\Throwable $e) {
                // fall through to first site
            }
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
        } catch (\Throwable $e) {
            return null;
        }

        return $sites[0] ?? null;
    }

    /** Shares the citation kill-switch with the Highwire/DC meta tags. */
    private function enabled(): bool
    {
        $value = $this->settings->get('dre_seo_citation_meta', '1');

        return $value === '1' || $value === 1 || $value === true;
    }

    private function fileResponse(string $content, string $contentType, string $filename): Response
    {
        $response = $this->getResponse();
        $response->setContent($content);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $contentType);
        // filename() is sanitised to [A-Za-z0-9._-], so it is safe unquoted here.
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $headers->addHeaderLine('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);

        return $response;
    }
}
