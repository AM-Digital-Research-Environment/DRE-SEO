<?php
declare(strict_types=1);

namespace DRESeo\Service\Citation;

use DRESeo\Service\CitationKind;

/**
 * A normalised, CSL-shaped citation for one DRE item — the single value every
 * citation consumer reads: the formatted Chicago/APA/MLA text, the BibTeX / RIS
 * / CSL-JSON exports, the Highwire meta tags and the theme's "Cite this record"
 * rail.
 *
 * Two shapes share it. A **work** (a publication, a research item, a podcast)
 * carries its creators, its publication date and its container. An **entry** —
 * a person, a place, a subject heading, any record whose kind is an authority
 * record — is cited as a page of the database: its title, the database name
 * ($archive), the body that publishes the database ($publisher) and the date the
 * record last changed ($updated), with no creators at all.
 *
 * The field conventions it normalises away are the DRE's own, and they differ
 * from a plain Dublin Core reading:
 *   • the container (journal, proceedings, book) is a linked record in
 *     **dcterms:isPartOf** — the Journal authority items in item set 41268 —
 *     while **dcterms:publisher** is a separate field, not a container;
 *   • a report's issuing body is that same dcterms:publisher; a thesis's
 *     awarding institution is its degree grantor (marcrel:dgg) before its
 *     publisher, and its degree is its FaBiO resource class;
 *   • dates are NumericDataTypes timestamps (YYYY, YYYY-MM or YYYY-MM-DD);
 *   • the accession id is **dre:id**;
 *   • DOIs live in **bibo:doi**;
 *   • authors are linked Person records ("First Last") or literals; a creator
 *     linked to an Organisation record is treated as an institution
 *     (single-field name, never inverted or split).
 */
final class CitationRecord
{
    /**
     * @param Creator[] $authors
     * @param Creator[] $editors
     * @param string[]  $keywords
     */
    public function __construct(
        public readonly int $id,
        public readonly CitationKind $kind,
        public readonly ?string $title = null,
        public readonly array $authors = [],
        public readonly array $editors = [],
        public readonly IssuedDate $issued = new IssuedDate(),
        /** Journal / proceedings / blog title — the work this one sits inside. */
        public readonly ?string $container = null,
        /** Book publisher, or a thesis/report institution. */
        public readonly ?string $publisher = null,
        /** The book a chapter belongs to. */
        public readonly ?string $bookTitle = null,
        public readonly ?string $volume = null,
        public readonly ?string $issue = null,
        public readonly ?string $pageFirst = null,
        public readonly ?string $pageLast = null,
        public readonly ?string $issn = null,
        public readonly ?string $isbn = null,
        public readonly ?string $doi = null,
        public readonly ?string $url = null,
        public readonly ?string $language = null,
        public readonly ?string $abstract = null,
        public readonly array $keywords = [],
        /** The DRE accession id (dre:id). */
        public readonly ?string $accession = null,
        /**
         * A thesis's degree — 'doctoral', 'masters' or 'bachelors' — or null
         * when the record does not name one (a habilitation, or any other kind).
         */
        public readonly ?string $degree = null,
        /** Place of publication (marcrel:pup) — exported, not printed. */
        public readonly ?string $place = null,
        /** A podcast's episode number. */
        public readonly ?string $number = null,
        /** Where a conference paper was presented, when it has no proceedings. */
        public readonly ?string $event = null,
        /** The platform a video is published on ("YouTube"), from its watch URL. */
        public readonly ?string $platform = null,
        /**
         * The form of the work in words: an entry's label ("Person record") or a
         * research item's resource type ("Moving image"). CSL `genre`.
         */
        public readonly ?string $genre = null,
        /** The database the record is held in — the AMIRA site title. */
        public readonly ?string $archive = null,
        /** When the record itself last changed; an entry's only date. */
        public readonly IssuedDate $updated = new IssuedDate(),
        /** The role the authors hold when it is not authorship ('host'). */
        public readonly ?string $authorRole = null,
    ) {
    }

    /** Whether this is an entry citation (a descriptive record), not a work. */
    public function isEntry(): bool
    {
        return $this->kind->isAuthorityRecord();
    }

    /**
     * Normalise a catalogued title for citation: ISBD's spaced colon ("Title :
     * Subtitle"), which ERef and EPub records carry, becomes the colon every
     * citation style expects, and runs of whitespace collapse.
     */
    public static function tidyTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = preg_replace('/\s+:\s+/u', ': ', $title) ?? $title;

        return $title !== '' ? $title : null;
    }

    /**
     * Read a page range written as text — "S. 23-38", "pp. 5–17", "112" — into
     * a first and last page. Returns [null, null] for anything else.
     *
     * @return array{0:?string,1:?string}
     */
    public static function splitPages(?string $pages): array
    {
        if ($pages === null || !preg_match('/(\d+)(?:\s*[-–—]\s*(\d+))?/u', $pages, $m)) {
            return [null, null];
        }

        return [$m[1], ($m[2] ?? '') !== '' ? $m[2] : null];
    }

    /**
     * Join a first/last page pair: "185-209", a single page when they match or
     * only one is present, else null. Static because the same rule applies when
     * reading the pair straight off a resource, before a record exists.
     */
    public static function joinPages(?string $first, ?string $last): ?string
    {
        if ($first === null && $last === null) {
            return null;
        }
        if ($first !== null && $last !== null && $last !== $first) {
            return $first . '-' . $last;
        }
        return $first ?? $last;
    }

    public function pageRange(): ?string
    {
        return self::joinPages($this->pageFirst, $this->pageLast);
    }

    /** The CSL item type, for CSL-JSON export and downstream typing. */
    public function cslType(): string
    {
        return $this->kind->cslType();
    }

    /** The DOI as a resolvable link, else the item URL, else null. */
    public function link(): ?string
    {
        if ($this->doi !== null && $this->doi !== '') {
            return 'https://doi.org/' . $this->doi;
        }
        return ($this->url ?? '') !== '' ? $this->url : null;
    }

    /**
     * The array form handed to the theme's rail partial. The key set and value
     * types are a published contract — the theme reads them by name — so
     * changing one is a breaking change for the theme, not an internal edit.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'kind'      => $this->kind->value,
            'cslType'   => $this->cslType(),
            'title'     => $this->title,
            'authors'   => array_map(static fn (Creator $c): array => $c->toArray(), $this->authors),
            'editors'   => array_map(static fn (Creator $c): array => $c->toArray(), $this->editors),
            'issued'    => $this->issued->toArray(),
            'container' => $this->container,
            'publisher' => $this->publisher,
            'bookTitle' => $this->bookTitle,
            'volume'    => $this->volume,
            'issue'     => $this->issue,
            'pageFirst' => $this->pageFirst,
            'pageLast'  => $this->pageLast,
            'issn'      => $this->issn,
            'isbn'      => $this->isbn,
            'doi'       => $this->doi,
            'url'       => $this->url,
            'language'  => $this->language,
            'abstract'  => $this->abstract,
            'keywords'  => $this->keywords,
            'accession' => $this->accession,
            'degree'    => $this->degree,
            'place'     => $this->place,
            'number'    => $this->number,
            'event'     => $this->event,
            'platform'  => $this->platform,
            'genre'     => $this->genre,
            'archive'   => $this->archive,
            'updated'   => $this->updated->toArray(),
            'authorRole' => $this->authorRole,
            'isEntry'   => $this->isEntry(),
        ];
    }

    /**
     * The inverse of {@see toArray()}, so the theme-facing array form can be
     * read back into a record.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string =>
            isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        $creators = static fn (string $key): array => array_map(
            static fn (array $person): Creator => Creator::fromArray($person),
            is_array($data[$key] ?? null) ? $data[$key] : []
        );

        return new self(
            id: (int) ($data['id'] ?? 0),
            kind: CitationKind::tryFrom((string) ($data['kind'] ?? '')) ?? CitationKind::Item,
            title: $string('title'),
            authors: $creators('authors'),
            editors: $creators('editors'),
            issued: IssuedDate::fromArray(is_array($data['issued'] ?? null) ? $data['issued'] : []),
            container: $string('container'),
            publisher: $string('publisher'),
            bookTitle: $string('bookTitle'),
            volume: $string('volume'),
            issue: $string('issue'),
            pageFirst: $string('pageFirst'),
            pageLast: $string('pageLast'),
            issn: $string('issn'),
            isbn: $string('isbn'),
            doi: $string('doi'),
            url: $string('url'),
            language: $string('language'),
            abstract: $string('abstract'),
            keywords: array_values(array_filter(
                is_array($data['keywords'] ?? null) ? $data['keywords'] : [],
                'is_string'
            )),
            accession: $string('accession'),
            degree: $string('degree'),
            place: $string('place'),
            number: $string('number'),
            event: $string('event'),
            platform: $string('platform'),
            genre: $string('genre'),
            archive: $string('archive'),
            updated: IssuedDate::fromArray(is_array($data['updated'] ?? null) ? $data['updated'] : []),
            authorRole: $string('authorRole'),
        );
    }
}
