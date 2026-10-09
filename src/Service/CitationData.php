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
 * {@see CitationExport} (BibTeX/RIS/CSL-JSON), {@see CitationMeta} (the Highwire
 * tags) and the Citation view helper.
 *
 * This class knows the *archive*: which Omeka property holds which citation
 * field. The record it returns knows nothing about Omeka, and the formatters
 * that read it know nothing about either.
 *
 * Dispatch is by **resource template** via the {@see CitationKindMap}, and the
 * property conventions are:
 *   • the container (journal, proceedings, book, series, a research item's
 *     project) is the linked record in **dcterms:isPartOf**; **dcterms:publisher**
 *     is its own field and doubles as a report's issuing body;
 *   • a thesis's awarding institution is its degree grantor (**marcrel:dgg**),
 *     and its degree is its resource class (fabio:MastersThesis, …);
 *   • authors come from the first populated of bibo:authorList, dcterms:creator,
 *     marcrel:aut and marcrel:cre — with podcasts and videos reading their
 *     host/speaker roles first, since they carry no dcterms:creator at all;
 *   • dates are NumericDataTypes timestamps, read raw so month and day survive;
 *   • the accession id is **dre:id**; DOIs live in **bibo:doi**, ISBNs in
 *     **bibo:isbn13**.
 *
 * Authority records (person, place, organisation, project, research section,
 * vocabulary term, journal) are not works. They build an *entry* record
 * instead — title, database, publisher, last-modified date — which the styles
 * cite as a page of the AMIRA database.
 */
final class CitationData
{
    use ResourceValueReader;

    /** Generic creator roles, tried after any kind-specific ones. */
    private const AUTHOR_TERMS = ['bibo:authorList', 'dcterms:creator', 'marcrel:aut', 'marcrel:cre'];
    private const EDITOR_TERMS = ['bibo:editorList', 'marcrel:edt'];

    /** Thesis degree per FaBiO class term; see {@see degree()}. */
    private const THESIS_DEGREES = [
        'fabio:DoctoralThesis' => 'doctoral',
        'fabio:MastersThesis' => 'masters',
        'fabio:BachelorsThesis' => 'bachelors',
    ];

    /** Watch-URL hosts that name a video platform. */
    private const PLATFORMS = [
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'vimeo.com' => 'Vimeo',
    ];

    /**
     * Built records per item object, then per URL and database name. Keyed by
     * the representation itself, not its id, so a record can never outlive the
     * item it was built from.
     *
     * @var \WeakMap<ItemRepresentation,array<string,CitationRecord>>
     */
    private \WeakMap $built;

    /**
     * @param string|null $publisher who publishes the database, for entry
     *                               citations ("Africa Multiple Cluster of
     *                               Excellence, University of Bayreuth")
     */
    public function __construct(
        private readonly CitationKindMap $kinds,
        private readonly ?string $publisher = null,
    ) {
        $this->built = new \WeakMap();
    }

    public function kind(?int $templateId): CitationKind
    {
        return $this->kinds->forTemplateId($templateId);
    }

    /**
     * Whether an item on this template is a citable *work* (not an authority
     * record). Authority records still get an entry citation from build(); this
     * is the question the Highwire tags and the theme's panel title ask.
     */
    public function isCitable(?int $templateId): bool
    {
        return !$this->kind($templateId)->isAuthorityRecord();
    }

    /**
     * The citation record for an item: a work, or an entry for an authority
     * record. Memoised per item, URL and database name, since the meta tags,
     * the record panel and the description fallback all ask for it on one page.
     *
     * @param string|null $url     the item's public (canonical) page URL
     * @param string|null $archive the database the item is held in (the site title)
     */
    public function build(ItemRepresentation $item, ?string $url = null, ?string $archive = null): CitationRecord
    {
        $url = ($url !== null && $url !== '') ? $url : null;
        $archive = ($archive !== null && trim($archive) !== '') ? trim($archive) : null;
        $key = $url . "\0" . $archive;
        $records = $this->built[$item] ?? [];
        if (!isset($records[$key])) {
            $records[$key] = $this->kinds->forResource($item)->isAuthorityRecord()
                ? $this->buildEntry($item, $url, $archive)
                : $this->buildWork($item, $url, $archive);
            $this->built[$item] = $records;
        }

        return $records[$key];
    }

    private function buildWork(ItemRepresentation $item, ?string $url, ?string $archive): CitationRecord
    {
        $kind = $this->kinds->forResource($item);

        // isPartOf is the container; a chapter's container IS its book title.
        $container = CitationRecord::tidyTitle($this->firstLabel($item, 'dcterms:isPartOf'));
        $abstract = $this->firstString($item, self::ABSTRACT_TERMS);
        [$authorTerm, $authors] = $this->people($item, [...$kind->creatorTerms(), ...self::AUTHOR_TERMS]);
        [$pageFirst, $pageLast] = $this->pageBounds($item);

        return new CitationRecord(
            id: $item->id(),
            kind: $kind,
            title: CitationRecord::tidyTitle($this->firstString($item, ['dcterms:title']) ?? $this->displayTitle($item)),
            authors: $authors,
            editors: $this->people($item, self::EDITOR_TERMS)[1],
            issued: $this->issued($item),
            container: $kind === CitationKind::Chapter ? null : $container,
            publisher: $kind === CitationKind::Thesis
                ? ($this->firstLabel($item, 'marcrel:dgg') ?? $this->firstLabel($item, 'dcterms:publisher'))
                : $this->firstLabel($item, 'dcterms:publisher'),
            bookTitle: $kind === CitationKind::Chapter ? $container : null,
            volume: $this->firstString($item, ['bibo:volume']),
            issue: $this->firstString($item, ['bibo:issue']),
            pageFirst: $pageFirst,
            pageLast: $pageLast,
            issn: $this->firstString($item, ['bibo:issn']),
            isbn: $this->isbn($item),
            doi: $this->doi($item),
            url: $url,
            language: $this->firstLabel($item, 'dcterms:language'),
            abstract: $abstract !== null ? $this->clip($abstract) : null,
            keywords: $this->keywords($item),
            accession: $this->accession($item),
            degree: $kind === CitationKind::Thesis ? $this->degree($item) : null,
            place: $this->firstLabel($item, 'marcrel:pup'),
            number: $kind === CitationKind::Podcast ? $this->firstString($item, ['bibo:number']) : null,
            event: $kind === CitationKind::Conference ? $this->firstLabel($item, 'bibo:presentedAt') : null,
            platform: $kind === CitationKind::Video ? $this->platform($item) : null,
            // A research item's resource type is its form ("Moving image");
            // a publication's form is already its kind.
            genre: $kind === CitationKind::Item ? $this->firstLabel($item, 'dcterms:type') : null,
            // A research item is held in the archive; a publication is only
            // catalogued there, so naming AMIRA as its archive would mislead.
            archive: $kind === CitationKind::Item ? $archive : null,
            updated: $this->modifiedDate($item),
            authorRole: $authorTerm !== null ? $kind->roleFor($authorTerm) : null,
        );
    }

    /**
     * An authority record, cited as an entry of the database that holds it:
     * no creators, no publication date — the record's title, what kind of
     * record it is, the database, its publisher and when the record last
     * changed. A curated record changes over time, so that date is the one
     * that tells a reader which state of it was cited.
     */
    private function buildEntry(ItemRepresentation $item, ?string $url, ?string $archive): CitationRecord
    {
        $kind = $this->kinds->forResource($item);
        $publisher = $this->publisher !== null && trim($this->publisher) !== '' ? trim($this->publisher) : null;

        return new CitationRecord(
            id: $item->id(),
            kind: $kind,
            title: CitationRecord::tidyTitle($this->displayTitle($item) ?? $this->firstString($item, ['dcterms:title'])),
            publisher: $publisher,
            url: $url,
            accession: $this->accession($item),
            genre: $kind->entryLabel(),
            archive: $archive,
            updated: $this->modifiedDate($item),
        );
    }

    /**
     * A thesis's degree, from its FaBiO class. The doctoral, habilitation,
     * master's and bachelor's templates all share the 'thesis' kind, so the
     * class is the one place the degree is recorded. The habilitation
     * template's class (plain fabio:Thesis) names none, so it stays null.
     */
    private function degree(ItemRepresentation $item): ?string
    {
        $class = $item->resourceClass();

        return $class ? (self::THESIS_DEGREES[$class->term()] ?? null) : null;
    }

    /** The platform a video's watch URL (fabio:hasURL) points at, if known. */
    private function platform(ItemRepresentation $item): ?string
    {
        foreach ($this->uris($item, 'fabio:hasURL') as $uri) {
            $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
            $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;
            if (isset(self::PLATFORMS[$host])) {
                return self::PLATFORMS[$host];
            }
        }
        return null;
    }

    // ─── Creators ────────────────────────────────────────────────────────────

    /**
     * Structured creators from the first populated role property, in document
     * order, with the term they came from (which decides a role label such as
     * a podcast's "host"). Institutions (creators linked to an Organisation
     * record) keep a single-field literal name; everyone else is split into
     * given/family.
     *
     * @param string[] $terms
     * @return array{0:?string,1:Creator[]}
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
                return [$term, $out];
            }
        }
        return [null, []];
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
