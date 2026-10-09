<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Emits bibliographic <meta> tags so the Zotero Connector (and Google Scholar,
 * Mendeley, etc.) capture a resource page as a proper reference.
 *
 * Two vocabularies, both read by Zotero's "Embedded Metadata" translator:
 *   • Highwire Press  (citation_title, citation_author, citation_journal_title …)
 *     — the richest signal and what drives Zotero's item-type detection and
 *     Google Scholar's indexing. Emitted for the citable works only: items whose
 *     template is a publication, a research item, a podcast or a video. Item-set
 *     and media pages, and descriptive records (people, places, subject
 *     headings), carry none, so Scholar never indexes them as articles.
 *   • Dublin Core     (DC.title, DC.creator, DC.date …) — a generic fallback,
 *     emitted for every resource (including person / place / organisation pages).
 *
 * The Highwire tags are written from the same {@see CitationRecord} the record
 * page's formatted citations and downloads read, so the panel, the downloads
 * and what Zotero captures cannot disagree about a field.
 */
class CitationMeta
{
    use ResourceValueReader;

    public function __construct(
        private readonly CitationKindMap $kinds,
        private readonly CitationData $citationData,
    ) {
    }

    public function apply(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical
    ): void {
        $headMeta = $view->headMeta();
        foreach ($this->tags($resource, $canonical) as [$name, $content]) {
            $headMeta->appendName($name, $content);
        }
    }

    /**
     * The tags for a resource page, in emission order. A name repeats only
     * where the vocabulary means a list (authors, editors, subjects).
     *
     * @return array<int,array{0:string,1:string}>
     */
    public function tags(AbstractResourceEntityRepresentation $resource, ?string $canonical): array
    {
        $kind = $this->kinds->forResource($resource);
        $isWork = $resource instanceof ItemRepresentation && !$kind->isAuthorityRecord();

        $tags = $this->dublinCore($resource, $canonical, $isWork ? $kind : null);
        if ($isWork) {
            /** @var ItemRepresentation $resource */
            $tags = [...$tags, ...$this->highwire($this->citationData->build($resource, $canonical), $resource, $canonical)];
        }
        return $tags;
    }

    // ─── Highwire Press (citation_*) ────────────────────────────────────────

    /** @return array<int,array{0:string,1:string}> */
    private function highwire(CitationRecord $record, ItemRepresentation $item, ?string $canonical): array
    {
        $tags = [];
        $add = static function (string $name, ?string $content) use (&$tags): void {
            if ($content !== null && $content !== '') {
                $tags[] = [$name, $content];
            }
        };

        $add('citation_title', $record->title);
        foreach ($record->authors as $author) {
            $add('citation_author', $author->literal);
        }
        // Editors (e.g. the book's editors for a chapter). Zotero's Embedded
        // Metadata translator maps repeated citation_editor tags to the Editor
        // role; DC.contributor would mis-file them as non-citation contributors.
        foreach ($record->editors as $editor) {
            $add('citation_editor', $editor->literal);
        }
        $add('citation_publication_date', $this->scholarDate($record->issued));
        $add('citation_language', $record->language);
        $add('citation_doi', $record->doi);
        if ($record->keywords !== []) {
            $add('citation_keywords', implode('; ', $record->keywords));
        }
        $add('citation_abstract', $record->abstract);
        $add('citation_public_url', $canonical);
        $add('citation_pdf_url', $this->pdfUrl($item));

        switch ($record->kind) {
            case CitationKind::Article:
                $add('citation_journal_title', $record->container);
                $add('citation_issn', $record->issn);
                $add('citation_volume', $record->volume);
                $add('citation_issue', $record->issue);
                $add('citation_firstpage', $record->pageFirst);
                $add('citation_lastpage', $record->pageLast);
                $add('citation_publisher', $record->publisher);
                break;
            case CitationKind::Conference:
                $add('citation_conference_title', $record->container ?? $record->event);
                $add('citation_firstpage', $record->pageFirst);
                $add('citation_lastpage', $record->pageLast);
                $add('citation_publisher', $record->publisher);
                break;
            case CitationKind::Chapter:
                $add('citation_inbook_title', $record->bookTitle);
                $add('citation_isbn', $record->isbn);
                $add('citation_publisher', $record->publisher);
                $add('citation_firstpage', $record->pageFirst);
                $add('citation_lastpage', $record->pageLast);
                break;
            case CitationKind::Book:
                $add('citation_isbn', $record->isbn);
                $add('citation_publisher', $record->publisher);
                break;
            case CitationKind::Thesis:
                $add('citation_dissertation_institution', $record->publisher);
                break;
            case CitationKind::Report:
                $add('citation_technical_report_institution', $record->publisher);
                break;
            // Podcasts and videos have no Highwire container tag; DC.type
            // carries their Zotero type. Their hosts and speakers ride
            // citation_author, which Zotero folds onto the type's primary
            // creator role (podcaster, director).
        }
        return $tags;
    }

    /**
     * Google Scholar's date form: "2025/03/20", "2025/03" or "2025", as precise
     * as the record; an unparseable literal passes through as entered.
     */
    private function scholarDate(IssuedDate $date): ?string
    {
        if ($date->year === null) {
            return $date->literal;
        }
        $out = sprintf('%04d', $date->year);
        if ($date->month !== null) {
            $out .= sprintf('/%02d', $date->month);
            if ($date->day !== null) {
                $out .= sprintf('/%02d', $date->day);
            }
        }
        return $out;
    }

    // ─── Dublin Core (DC.*) ─────────────────────────────────────────────────

    /** @return array<int,array{0:string,1:string}> */
    private function dublinCore(
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical,
        ?CitationKind $workKind
    ): array {
        $tags = [];
        $add = static function (string $name, ?string $content) use (&$tags): void {
            if ($content !== null && $content !== '') {
                $tags[] = [$name, $content];
            }
        };

        $add('DC.title', $this->firstString($resource, ['dcterms:title']));
        foreach ($this->people($resource, ['dcterms:creator', 'bibo:authorList', 'marcrel:aut']) as $creator) {
            $add('DC.creator', $creator);
        }
        $add('DC.date', $this->firstString($resource, self::DATE_TERMS));
        $add('DC.publisher', $this->firstLabel($resource, 'dcterms:publisher'));
        // A work names its exact Zotero item type here: the Embedded Metadata
        // translator accepts any Zotero type id in DC.type and lets it win over
        // its citation_title guess (journalArticle), which is how a podcast, a
        // video or a dataset is saved as one. Anything else gives its class.
        $add('DC.type', $workKind?->zoteroType()
            ?? ($resource->resourceClass() ? $resource->resourceClass()->label() : null));
        $add('DC.language', $this->firstLabel($resource, 'dcterms:language'));

        // DC.identifier is the resource's own (canonical) URL. The DOI is
        // conveyed solely through citation_doi → Zotero's DOI field; emitting it
        // here as well made Zotero copy it into the Extra field redundantly.
        $add('DC.identifier', $canonical);

        foreach ($this->labels($resource, 'dcterms:subject') as $subject) {
            $add('DC.subject', $subject);
        }
        $description = $this->firstString($resource, ['dcterms:description', 'dcterms:abstract']);
        if ($description !== null) {
            $add('DC.description', $this->clip($description));
        }
        return $tags;
    }

    /**
     * Person names from the first populated role property, in document order.
     *
     * @param string[] $terms
     * @return string[]
     */
    private function people(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        foreach ($terms as $term) {
            $names = $this->labels($resource, $term);
            if ($names) {
                return $names;
            }
        }
        return [];
    }
}
