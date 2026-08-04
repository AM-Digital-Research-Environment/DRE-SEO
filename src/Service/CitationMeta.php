<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Emits bibliographic <meta> tags so the Zotero Connector (and Google Scholar,
 * Mendeley, etc.) capture a resource page as a proper reference.
 *
 * Two vocabularies, both read by Zotero's "Embedded Metadata" translator:
 *   • Highwire Press  (citation_title, citation_author, citation_journal_title …)
 *     — the richest signal and what drives Zotero's item-type detection. Emitted
 *     for the bibliographic templates (research items + the publication types).
 *   • Dublin Core     (DC.title, DC.creator, DC.date …) — a generic fallback,
 *     emitted for every resource (including person / place / organisation pages).
 *
 * The Highwire tag set per template id is config-driven (dre_seo.citation
 * .template_kinds) so the mapping can be tuned without touching this class.
 */
class CitationMeta
{
    private const ABSTRACT_MAX = 5000;

    /**
     * @param array<int,string> $templateKinds resource template id => citation kind
     */
    public function __construct(
        private readonly array $templateKinds,
        private readonly string $defaultKind = 'item',
    ) {
    }

    public function apply(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        ?int $templateId,
        ?string $canonical
    ): void {
        $headMeta = $view->headMeta();
        $kind = $this->templateKinds[$templateId] ?? $this->defaultKind;

        // Dublin Core for every resource.
        $this->dublinCore($headMeta, $resource, $canonical);

        // Highwire only for citable works. Whether a kind is a descriptive
        // record is CitationKind's to answer — this class kept its own list of
        // kind strings, and the two drifted: the Authority Resource template
        // resolved to the 'item' default and so handed Zotero a citation_title
        // for vocabulary terms like "Artefact".
        if (!$this->isAuthority($kind)) {
            $this->highwire($headMeta, $resource, $kind, $canonical);
        }
    }

    /**
     * Whether this kind is a descriptive record rather than a citable work.
     * An unknown kind string (a config typo) is treated as citable, matching
     * the default_kind fallback rather than silently muting a real work.
     */
    private function isAuthority(string $kind): bool
    {
        return CitationKind::tryFrom($kind)?->isAuthorityRecord() ?? false;
    }

    // ─── Highwire Press (citation_*) ────────────────────────────────────────

    private function highwire(
        \Laminas\View\Helper\HeadMeta $headMeta,
        AbstractResourceEntityRepresentation $resource,
        string $kind,
        ?string $canonical
    ): void {
        $this->single($headMeta, 'citation_title', $this->firstString($resource, ['dcterms:title']));

        foreach ($this->people($resource, ['bibo:authorList', 'dcterms:creator', 'marcrel:aut']) as $author) {
            $headMeta->appendName('citation_author', $author);
        }

        // Editors (e.g. the book's editors for a chapter). Zotero's Embedded
        // Metadata translator maps repeated citation_editor tags to the Editor
        // role; DC.contributor would mis-file them as non-citation contributors,
        // so the Highwire tag is the correct channel.
        foreach ($this->people($resource, ['bibo:editorList', 'marcrel:edt']) as $editor) {
            $headMeta->appendName('citation_editor', $editor);
        }

        $this->single($headMeta, 'citation_publication_date',
            $this->firstString($resource, ['dcterms:issued', 'dcterms:date', 'dcterms:created']));
        $this->single($headMeta, 'citation_language', $this->firstLabel($resource, 'dcterms:language'));
        $this->single($headMeta, 'citation_doi', $this->doi($resource));

        $keywords = $this->labels($resource, 'dcterms:subject');
        if ($keywords) {
            $this->single($headMeta, 'citation_keywords', implode('; ', $keywords));
        }

        $abstract = $this->firstString($resource, ['bibo:abstract', 'dcterms:abstract', 'dcterms:description']);
        if ($abstract !== null) {
            $this->single($headMeta, 'citation_abstract', $this->clip($abstract));
        }
        if ($canonical) {
            $this->single($headMeta, 'citation_public_url', $canonical);
        }
        $pdf = $this->pdfUrl($resource);
        if ($pdf !== null) {
            $this->single($headMeta, 'citation_pdf_url', $pdf);
        }

        $container = $this->firstLabel($resource, 'dcterms:isPartOf');
        $publisher = $this->firstLabel($resource, 'dcterms:publisher');
        $firstPage = $this->firstString($resource, ['bibo:pageStart']);
        $lastPage = $this->firstString($resource, ['bibo:pageEnd']);

        switch ($kind) {
            case 'article':
                $this->single($headMeta, 'citation_journal_title', $container);
                $this->single($headMeta, 'citation_issn', $this->firstString($resource, ['bibo:issn']));
                $this->single($headMeta, 'citation_volume', $this->firstString($resource, ['bibo:volume']));
                $this->single($headMeta, 'citation_issue', $this->firstString($resource, ['bibo:issue']));
                $this->single($headMeta, 'citation_firstpage', $firstPage);
                $this->single($headMeta, 'citation_lastpage', $lastPage);
                $this->single($headMeta, 'citation_publisher', $publisher);
                break;
            case 'conference':
                $this->single($headMeta, 'citation_conference_title', $container);
                $this->single($headMeta, 'citation_firstpage', $firstPage);
                $this->single($headMeta, 'citation_lastpage', $lastPage);
                break;
            case 'chapter':
                $this->single($headMeta, 'citation_inbook_title', $container);
                $this->single($headMeta, 'citation_isbn', $this->firstString($resource, ['bibo:isbn']));
                $this->single($headMeta, 'citation_publisher', $publisher);
                $this->single($headMeta, 'citation_firstpage', $firstPage);
                $this->single($headMeta, 'citation_lastpage', $lastPage);
                break;
            case 'book':
                $this->single($headMeta, 'citation_isbn', $this->firstString($resource, ['bibo:isbn']));
                $this->single($headMeta, 'citation_publisher', $publisher);
                break;
            case 'thesis':
                $this->single($headMeta, 'citation_dissertation_institution', $publisher ?? $container);
                break;
            case 'report':
                $this->single($headMeta, 'citation_technical_report_institution', $publisher ?? $container);
                break;
            case 'podcast':
                // Zotero has no Highwire container tag for podcasts, so the
                // item type rides on Dublin Core instead: the Embedded
                // Metadata translator hands DC.type to its RDF backend, which
                // accepts any exact Zotero type id (ZU.itemTypeExists). This
                // wins over the generic class-label DC.type set in dublinCore()
                // and over citation_title's journalArticle fall-back guess.
                // (seriesTitle / episodeNumber have no flat-meta equivalent —
                // Zotero only reads those from a nested RDF Series node.)
                $this->single($headMeta, 'DC.type', 'podcast');

                // Host = podcaster (Zotero's primary podcast creator), then
                // guests. Both ride citation_author; Zotero folds the base
                // "author" role onto the podcast's creator types (there is no
                // citation_guest channel). The generic citation_author block
                // above finds nothing here — podcasts use marcrel:hst / :spk.
                foreach ($this->people($resource, ['marcrel:hst']) as $host) {
                    $headMeta->appendName('citation_author', $host);
                }
                foreach ($this->people($resource, ['marcrel:spk']) as $guest) {
                    $headMeta->appendName('citation_author', $guest);
                }
                break;
            case 'video':
                // Zotero recognises this exact Dublin Core item type. Speakers
                // are the closest available creator role in the video schema.
                $this->single($headMeta, 'DC.type', 'videoRecording');
                foreach ($this->people($resource, ['marcrel:spk']) as $speaker) {
                    $headMeta->appendName('citation_author', $speaker);
                }
                break;
            // 'dataset', 'post', 'item': title/author/date/abstract already cover them.
        }
    }

    // ─── Dublin Core (DC.*) ─────────────────────────────────────────────────

    private function dublinCore(
        \Laminas\View\Helper\HeadMeta $headMeta,
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical
    ): void {
        $this->single($headMeta, 'DC.title', $this->firstString($resource, ['dcterms:title']));
        foreach ($this->people($resource, ['dcterms:creator', 'bibo:authorList', 'marcrel:aut']) as $creator) {
            $headMeta->appendName('DC.creator', $creator);
        }
        $this->single($headMeta, 'DC.date',
            $this->firstString($resource, ['dcterms:issued', 'dcterms:date', 'dcterms:created']));
        $this->single($headMeta, 'DC.publisher', $this->firstLabel($resource, 'dcterms:publisher'));
        $this->single($headMeta, 'DC.type',
            $resource->resourceClass() ? $resource->resourceClass()->label() : null);
        $this->single($headMeta, 'DC.language', $this->firstLabel($resource, 'dcterms:language'));

        // DC.identifier is the resource's own (canonical) URL. The DOI is
        // conveyed solely through citation_doi → Zotero's DOI field; emitting it
        // here as well made Zotero copy it into the Extra field redundantly.
        $this->single($headMeta, 'DC.identifier', $canonical);

        foreach ($this->labels($resource, 'dcterms:subject') as $subject) {
            $headMeta->appendName('DC.subject', $subject);
        }
        $description = $this->firstString($resource, ['dcterms:description', 'dcterms:abstract']);
        if ($description !== null) {
            $this->single($headMeta, 'DC.description', $this->clip($description));
        }
    }

    // ─── Value readers ──────────────────────────────────────────────────────

    /** @param string[] $terms */
    private function firstString(AbstractResourceEntityRepresentation $resource, array $terms): ?string
    {
        foreach ($terms as $term) {
            $value = $resource->value($term);
            if ($value instanceof ValueRepresentation) {
                $text = trim(strip_tags((string) $value));
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }

    private function firstLabel(AbstractResourceEntityRepresentation $resource, string $term): ?string
    {
        $value = $resource->value($term);
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $linked = $value->valueResource();
        $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
        return $label !== '' ? $label : null;
    }

    /** @return string[] */
    private function labels(AbstractResourceEntityRepresentation $resource, string $term): array
    {
        $out = [];
        foreach ($resource->value($term, ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
            if ($label !== '') {
                $out[$label] = $label;
            }
        }
        return array_values($out);
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

    private function doi(AbstractResourceEntityRepresentation $resource): ?string
    {
        $doi = $this->firstString($resource, ['bibo:doi']);
        if ($doi === null) {
            return null;
        }
        // Normalise a DOI URL or "doi:" prefix down to the bare identifier.
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    private function pdfUrl(AbstractResourceEntityRepresentation $resource): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }
        foreach ($resource->media() as $media) {
            if (method_exists($media, 'isPublic') && !$media->isPublic()) {
                continue;
            }
            if ($media->mediaType() === 'application/pdf') {
                $url = $media->originalUrl();
                if ($url) {
                    return $url;
                }
            }
        }
        return null;
    }

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, self::ABSTRACT_MAX);
    }

    private function single(\Laminas\View\Helper\HeadMeta $headMeta, string $name, ?string $content): void
    {
        if ($content !== null && $content !== '') {
            $headMeta->setName($name, $content);
        }
    }
}
