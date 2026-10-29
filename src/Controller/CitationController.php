<?php
declare(strict_types=1);

namespace DRESeo\Controller;

use DRESeo\Controller\Concern\SendsResponses;
use DRESeo\Service\CitationData;
use DRESeo\Service\CitationExport;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\SettingsGate;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * /cite/:id/:format — single-item citation downloads (BibTeX, RIS, CSL-JSON,
 * plain text).
 *
 * Reuses the {@see CitationData} mapping + {@see CitationExport} serialisers that
 * back the theme's "Cite this record" rail, so a reader can save a record
 * straight into Zotero / Mendeley / EndNote / a LaTeX bibliography. Only public
 * items resolve. A work exports as its own type; an authority record (person,
 * place, subject heading, …) exports as a web page of the database, the same
 * entry citation the record page shows.
 *
 * The URL baked into the export is the record's persistent identifier — its
 * DRE Linked Data URI (`/resource/{id}`, which 303s to the page) — else the
 * *default site's* item page, so a downloaded citation points at the same
 * address regardless of which site the reader came from.
 *
 * The downloads are open to scripts on any origin (CORS), carry an ETag and
 * the record's Last-Modified, and may be cached for five minutes, so a
 * reference manager or a page that embeds a citation can revalidate cheaply.
 */
class CitationController extends AbstractActionController
{
    use SendsResponses;

    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationExport $citationExport,
        private readonly ApiManager $api,
        private readonly SettingsGate $settings,
        private readonly DefaultSite $defaultSite,
        private readonly ?LinkedDataIdentity $identity = null,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->settings->isOn('dre_seo_citation_meta', true)) {
            return $this->respondWithStatus(404);
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $format = (string) $this->params()->fromRoute('format', '');
        if ($id <= 0 || !isset(CitationExport::FORMATS[$format])) {
            return $this->respondWithStatus(404);
        }

        $item = $this->resolveItem($id);
        if ($item === null) {
            return $this->respondWithStatus(404);
        }

        $site = $this->defaultSite->get();
        $record = $this->citationData->build($item, $this->identity?->uri($item) ?? $this->itemUrl($item), $site?->title());
        $body = $this->citationExport->serialize($record, $format);
        if ($body === null) {
            return $this->respondWithStatus(404);
        }

        [, $contentType] = CitationExport::FORMATS[$format];

        // Readable by scripts on other sites, 304 included; filename() is
        // sanitised to [A-Za-z0-9._-], so it is safe unquoted here.
        return $this->respondCacheable(
            $body,
            $contentType,
            $this->modified($item),
            ['Access-Control-Allow-Origin' => '*'],
            ['Content-Disposition' => 'attachment; filename="' . $this->citationExport->filename($record, $format) . '"']
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveItem(int $id): ?ItemRepresentation
    {
        try {
            $item = $this->api->read('items', $id)->getContent();
        } catch (\Throwable $e) {
            return null;
        }

        return $item instanceof ItemRepresentation && $item->isPublic() ? $item : null;
    }

    /** The default site's public page URL — the citation's stable canonical. */
    private function itemUrl(ItemRepresentation $item): ?string
    {
        $site = $this->defaultSite->get();
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

    /** When the item last changed (else was created), for Last-Modified. */
    private function modified(ItemRepresentation $item): ?\DateTimeInterface
    {
        try {
            $date = $item->modified() ?? $item->created();
        } catch (\Throwable $e) {
            return null;
        }
        return $date instanceof \DateTimeInterface ? $date : null;
    }
}
