<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds a {@see CitationRecord} from a DRE item — the single source of truth
 * consumed by {@see CitationFormatter} (Chicago/APA/MLA text),
 * {@see CitationExport} (BibTeX/RIS/CSL-JSON) and the Citation view helper.
 *
 * This class knows the *archive*: which Omeka property holds which citation
 * field. The record it returns knows nothing about Omeka, and the formatters
 * that read it know nothing about either.
 *
 * Dispatch is by **resource template** via the same {@see CitationKindMap}
 * config map CitationMeta uses, and the property conventions are read to match
 * the Highwire tags that module already emits:
 *   • the container (journal, proceedings, book) is the linked record in
 *     **dcterms:isPartOf**; **dcterms:publisher** is its own field, and doubles
 *     as a thesis's awarding institution or a report's issuing body;
 *   • authors come from the first populated of bibo:authorList, dcterms:creator
 *     and marcrel:aut — with podcasts and videos reading their host/speaker
 *     roles first, since they carry no dcterms:creator at all;
 *   • dates are NumericDataTypes timestamps, read raw so month and day survive;
 *   • the accession id is **dre:id**; DOIs live in **bibo:doi**.
 *
 * Authority records (person / place / organisation / project / research
 * section) are not citable works: {@see build()} returns null for them and
 * {@see isCitable()} is false, so the theme hides the citation styles on those
 * pages.
 */
final class CitationData
{
    use ResourceValueReader;

    /** Generic creator roles, tried after any kind-specific ones. */
    private const AUTHOR_TERMS = ['bibo:authorList', 'dcterms:creator', 'marcrel:aut'];
    private const EDITOR_TERMS = ['bibo:editorList', 'marcrel:edt'];

    public function __construct(private readonly CitationKindMap $kinds)
    {
    }

    public function kind(?int $templateId): CitationKind
    {
        return $this->kinds->forTemplateId($templateId);
    }

    /** Whether an item on this template is a citable work (not an authority record). */
    public function isCitable(?int $templateId): bool
    {
        return !$this->kind($templateId)->isAuthorityRecord();
    }

    /**
     * Normalized citation record, or null for a non-citable authority record.
     *
     * @param string|null $url the item's public (canonical) page URL
     */
    public function build(ItemRepresentation $item, ?string $url = null): ?CitationRecord
    {
        $kind = $this->kinds->forResource($item);
        if ($kind->isAuthorityRecord()) {
            return null;
        }

        // isPartOf is the container; a chapter's container IS its book title.
        $container = $this->firstLabel($item, 'dcterms:isPartOf');
        $abstract = $this->firstString($item, self::ABSTRACT_TERMS);

        return new CitationRecord(
            id: $item->id(),
            kind: $kind,
            title: $this->firstString($item, ['dcterms:title']) ?? $this->displayTitle($item),
            authors: $this->people($item, [...$kind->creatorTerms(), ...self::AUTHOR_TERMS]),
            editors: $this->people($item, self::EDITOR_TERMS),
            issued: $this->issued($item),
            container: $kind === CitationKind::Chapter ? null : $container,
            publisher: $this->firstLabel($item, 'dcterms:publisher'),
            bookTitle: $kind === CitationKind::Chapter ? $container : null,
            volume: $this->firstString($item, ['bibo:volume']),
            issue: $this->firstString($item, ['bibo:issue']),
            pageFirst: $this->firstString($item, ['bibo:pageStart']),
            pageLast: $this->firstString($item, ['bibo:pageEnd']),
            issn: $this->firstString($item, ['bibo:issn']),
            isbn: $this->firstString($item, ['bibo:isbn']),
            doi: $this->doi($item),
            url: ($url !== null && $url !== '') ? $url : null,
            language: $this->firstLabel($item, 'dcterms:language'),
            abstract: $abstract !== null ? $this->clip($abstract) : null,
            keywords: $this->keywords($item),
            accession: $this->accession($item),
        );
    }

    // ─── Creators ────────────────────────────────────────────────────────────

    /**
     * Structured creators from the first populated role property, in document
     * order. Institutions (creators linked to an Organisation record) keep a
     * single-field literal name; everyone else is split into given/family.
     *
     * @param string[] $terms
     * @return Creator[]
     */
    private function people(ItemRepresentation $item, array $terms): array
    {
        foreach ($terms as $term) {
            $out = [];
            foreach ($item->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $linked = $value->valueResource();
                $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
                if ($label === '') {
                    continue;
                }
                $out[] = Creator::parse($label, $this->kinds->isOrganization($linked));
            }
            if ($out) {
                return $out;
            }
        }
        return [];
    }

    // ─── Field readers ───────────────────────────────────────────────────────

    /**
     * The publication date. Prefer ->value() (the raw stored form) over the
     * localized rendering, so month and day survive.
     */
    private function issued(ItemRepresentation $item): IssuedDate
    {
        foreach (self::DATE_TERMS as $term) {
            $value = $item->value($term);
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $raw = trim((string) $value->value());
            if ($raw === '') {
                $raw = trim(strip_tags((string) $value));
            }
            if ($raw !== '') {
                return IssuedDate::parse($raw);
            }
        }
        return IssuedDate::unknown();
    }

    /** The item's display title, for a record whose dcterms:title is empty. */
    private function displayTitle(ItemRepresentation $item): ?string
    {
        $title = trim((string) $item->displayTitle(''));

        return $title !== '' ? $title : null;
    }
}
