<?php
declare(strict_types=1);

namespace DRESeo\Service\Concern;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\LanguageCode;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Shared leaf value-readers for the citation, meta-tag and structured-data
 * services.
 *
 * These are the DRE's field conventions in one place: linked-resource labels
 * via displayTitle, the dcterms:subject keyword set, the dre:id accession, the
 * bibo:doi normalisation, the ISBN properties, the identifier URIs that become
 * `sameAs`, and the public-PDF lookup. {@see \DRESeo\Service\CitationData},
 * {@see \DRESeo\Service\CitationMeta} and {@see \DRESeo\Service\StructuredData}
 * all read through it, so a convention changes in one place.
 */
trait ResourceValueReader
{
    /**
     * Publication-date properties, in preference order — the same order
     * CitationMeta emits for citation_publication_date and DC.date.
     */
    private const DATE_TERMS = ['dcterms:issued', 'dcterms:date', 'dcterms:created'];

    /** Abstract/summary properties in citation preference order. */
    private const ABSTRACT_TERMS = ['bibo:abstract', 'dcterms:abstract', 'dcterms:description'];

    /** Abstracts are clipped to this many characters before being emitted. */
    private const ABSTRACT_MAX = 5000;

    /**
     * ISBN properties, preferred first. ERef and EPub records carry
     * bibo:isbn13; bibo:isbn is the generic term a hand-entered record may use.
     */
    private const ISBN_TERMS = ['bibo:isbn13', 'bibo:isbn', 'bibo:isbn10'];

    /**
     * The authorities whose IRIs are identity links (`sameAs`), each as the
     * pattern a stored URI must match and the canonical IRI it becomes — the
     * allowlist DRE Linked Data applies to its owl:sameAs / skos:exactMatch
     * links, so the page and the RDF name the same targets. Anything else in
     * dcterms:identifier (a YouTube playlist, a GEPRIS project page, a free
     * shelf mark) is not the same thing as the record and is never `sameAs`.
     */
    private const AUTHORITIES = [
        'wikidata' => ['~^https?://(?:www\.|m\.)?wikidata\.org/(?:entity|wiki)/(Q[1-9][0-9]*)/?$~i', 'http://www.wikidata.org/entity/$1'],
        'gnd'      => ['~^https?://d-nb\.info/gnd/([0-9X-]+)/?$~i', 'https://d-nb.info/gnd/$1'],
        'orcid'    => ['~^https?://(?:www\.)?orcid\.org/([0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X])/?$~i', 'https://orcid.org/$1'],
        'viaf'     => ['~^https?://(?:www\.)?viaf\.org/viaf/([0-9]+)/?$~i', 'http://viaf.org/viaf/$1'],
        'isni'     => ['~^https?://(?:www\.)?isni\.org/isni/([0-9]{15}[0-9X])/?$~i', 'https://isni.org/isni/$1'],
        'ror'      => ['~^https?://(?:www\.)?ror\.org/(0[a-z0-9]{8})/?$~i', 'https://ror.org/$1'],
        'loc'      => ['~^https?://id\.loc\.gov/authorities/(subjects|names|genreForms)/([a-z]+[0-9]+)(?:\.html)?$~', 'http://id.loc.gov/authorities/$1/$2'],
        'geonames' => ['~^https?://(?:www\.|sws\.)?geonames\.org/([0-9]+)(?:/.*)?$~i', 'https://sws.geonames.org/$1/'],
        'doi'      => ['~^https?://(?:dx\.)?doi\.org/(10\.[0-9]{4,9}/\S+)$~i', 'https://doi.org/$1'],
        'handle'   => ['~^https?://hdl\.handle\.net/([0-9.]+/\S+)$~i', 'https://hdl.handle.net/$1'],
    ];

    /**
     * First non-empty literal/label across the given terms, in order, tags stripped.
     *
     * @param string[] $terms
     */
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

    /** Label of the first value of $term: a linked resource's title, else the literal. */
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

    /**
     * All distinct labels for $term (linked titles or literals), in document order.
     *
     * @return string[]
     */
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
     * The keyword set: dcterms:subject labels, de-duplicated in document order.
     * Matches the citation_keywords tag CitationMeta emits.
     *
     * @return string[]
     */
    private function keywords(AbstractResourceEntityRepresentation $resource): array
    {
        return $this->labels($resource, 'dcterms:subject');
    }

    /**
     * The record's own accession id, used for cite keys and download filenames.
     *
     * MongoDB-synced research items carry `dre:id` ("abg-99-0000"). Publications
     * come from the ERef/EPub pipeline instead and carry an "eref-95983" style
     * `dcterms:identifier` — worth reaching for, since it makes a BibTeX key and
     * a downloaded filename mean something. The prefix test is deliberate: a
     * research item's dcterms:identifier is often free text (a shelf mark, or a
     * whole descriptive title), which would make a nonsense key.
     */
    private function accession(AbstractResourceEntityRepresentation $resource): ?string
    {
        $dreId = $this->firstString($resource, ['dre:id']);
        if ($dreId !== null) {
            return $dreId;
        }

        foreach ($resource->value('dcterms:identifier', ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $text = trim(strip_tags((string) $value));
            if (preg_match('/^e(?:ref|pub)-\S+$/i', $text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The bare DOI from bibo:doi (a doi.org URL or "doi:" prefix is normalised
     * down to the identifier). URI-typed values are read from their URI, so a
     * value entered without a label still yields a DOI.
     */
    private function doi(AbstractResourceEntityRepresentation $resource): ?string
    {
        $value = $resource->value('bibo:doi');
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $doi = trim(strip_tags((string) $value));
        if ($doi === '' || $value->type() === 'uri') {
            $doi = (string) ($value->uri() ?: $doi);
        }
        if ($doi === '') {
            return null;
        }
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $doi);
        return ($doi ?? '') !== '' ? $doi : null;
    }

    /** The first ISBN the record carries, as entered. */
    private function isbn(AbstractResourceEntityRepresentation $resource): ?string
    {
        return $this->firstString($resource, self::ISBN_TERMS);
    }

    /**
     * Every URI a property holds: URI-typed values by their URI, literals that
     * are themselves http(s) URLs, and a bare Wikidata id ("Q209829"), which
     * the location records store in place of the entity URI.
     *
     * @return string[]
     */
    private function uris(AbstractResourceEntityRepresentation $resource, string $term): array
    {
        $out = [];
        foreach ($resource->value($term, ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation || $value->valueResource()) {
                continue;
            }
            $uri = trim((string) ($value->uri() ?? ''));
            if ($uri === '') {
                $uri = trim(strip_tags((string) $value));
            }
            if (preg_match('/\AQ\d+\z/', $uri)) {
                $uri = 'http://www.wikidata.org/entity/' . $uri;
            }
            if (preg_match('#\Ahttps?://\S+\z#i', $uri)) {
                $out[$uri] = $uri;
            }
        }
        return array_values($out);
    }

    /**
     * The record's identity links — `sameAs` in JSON-LD: the authority IRIs in
     * dcterms:identifier (GND for people, Wikidata for places and institutions,
     * LCSH and Wikidata for subject headings), in their canonical form, and the
     * RDSpace handle. Only the {@see AUTHORITIES} qualify. WissKI links are
     * retired and deliberately never read.
     *
     * @return string[]
     */
    private function identityUris(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        foreach ($this->uris($resource, 'dcterms:identifier') as $uri) {
            $iri = $this->authorityIri($uri);
            if ($iri !== null) {
                $out[$iri] = $iri;
            }
        }
        $handle = $this->firstString($resource, ['dre:rdspaceHandle']);
        if ($handle !== null) {
            $iri = str_starts_with($handle, 'http') ? $handle : 'https://hdl.handle.net/' . ltrim($handle, '/');
            $out[$iri] = $iri;
        }
        return array_values($out);
    }

    /** A stored URI as its authority's canonical IRI, or null when it is no authority's. */
    private function authorityIri(string $uri): ?string
    {
        $uri = trim($uri);
        foreach (self::AUTHORITIES as [$pattern, $canonical]) {
            if (preg_match($pattern, $uri)) {
                return (string) preg_replace($pattern, $canonical, $uri);
            }
        }
        return null;
    }

    /** The ORCID iD among the IRIs, if any. */
    private static function orcidOf(array $iris): ?string
    {
        foreach ($iris as $iri) {
            if (is_string($iri) && preg_match('~^https://orcid\.org/[0-9X-]{19}$~', $iri)) {
                return $iri;
            }
        }
        return null;
    }

    /**
     * The record's languages as BCP 47 tags ("de", "pcm"), in document order:
     * a linked language record by the ISO 639 code it keeps in
     * dcterms:alternative, a literal when it is itself a code. A language
     * known only by its name yields no tag.
     *
     * @return string[]
     */
    private function languageCodes(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        foreach ($resource->value('dcterms:language', ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            $candidates = $linked
                ? array_map(
                    static fn ($alternative): string => trim(strip_tags((string) $alternative)),
                    $linked->value('dcterms:alternative', ['all' => true])
                )
                : [trim(strip_tags((string) $value))];
            foreach ($candidates as $candidate) {
                $tag = LanguageCode::toBcp47($candidate);
                if ($tag !== null) {
                    $out[$tag] = $tag;
                    break;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Whether an item is a research item: one of its dcterms:isPartOf values
     * links to a record of class foaf:Project. The rule DRE-theme's
     * RecordAccess applies, by resource class rather than template id, so the
     * theme's access notice and the structured data agree on which records
     * AMIRA describes but does not hold.
     */
    private function isResearchItem(AbstractResourceEntityRepresentation $resource): bool
    {
        if (!$resource instanceof ItemRepresentation) {
            return false;
        }
        foreach ($resource->value('dcterms:isPartOf', ['all' => true]) as $value) {
            $linked = $value instanceof ValueRepresentation ? $value->valueResource() : null;
            $class = $linked ? $linked->resourceClass() : null;
            if ($class && $class->term() === 'foaf:Project') {
                return true;
            }
        }
        return false;
    }

    /**
     * The first of $terms as an ISO 8601 date, as precise as stored
     * ("2018", "2018-12", "2018-12-07"). The raw stored value is read, so a
     * NumericDataTypes timestamp keeps its month and day; a value that names
     * no year yields null rather than free text.
     *
     * @param string[] $terms
     */
    private function isoDate(AbstractResourceEntityRepresentation $resource, array $terms): ?string
    {
        foreach ($terms as $term) {
            $value = $resource->value($term);
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $raw = trim((string) $value->value());
            if ($raw === '') {
                $raw = trim(strip_tags((string) $value));
            }
            if ($raw === '') {
                continue;
            }
            $date = IssuedDate::parse($raw);
            if ($date->year === null) {
                return null;
            }
            $out = sprintf('%04d', $date->year);
            if ($date->month !== null && $date->month >= 1 && $date->month <= 12) {
                $out .= sprintf('-%02d', $date->month);
                if ($date->day !== null && checkdate($date->month, $date->day, $date->year)) {
                    $out .= sprintf('-%02d', $date->day);
                }
            }
            return $out;
        }
        return null;
    }

    /** When the Omeka record last changed, to the day. */
    private function modifiedDate(AbstractResourceEntityRepresentation $resource): IssuedDate
    {
        try {
            $modified = $resource->modified();
        } catch (\Throwable $e) {
            return IssuedDate::unknown();
        }
        return $modified instanceof \DateTimeInterface
            ? IssuedDate::parse($modified->format('Y-m-d'))
            : IssuedDate::unknown();
    }

    /** The original URL of the item's first public PDF media, if any. */
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

    /**
     * The first and last page: bibo:pageStart/pageEnd, else the page range
     * ERef sometimes writes into bibo:pages as text ("S. 23-38").
     *
     * @return array{0:?string,1:?string}
     */
    private function pageBounds(AbstractResourceEntityRepresentation $resource): array
    {
        $first = $this->firstString($resource, ['bibo:pageStart']);
        $last = $this->firstString($resource, ['bibo:pageEnd']);
        if ($first === null && $last === null) {
            return CitationRecord::splitPages($this->firstString($resource, ['bibo:pages']));
        }
        return [$first, $last];
    }

    /**
     * The resource's citation page range. The join rule itself belongs to the
     * record, so a page range reads the same whether it came from a resource or
     * from a built citation.
     */
    private function pageRange(AbstractResourceEntityRepresentation $resource): ?string
    {
        return CitationRecord::joinPages(...$this->pageBounds($resource));
    }

    /** Whitespace-normalise, strip tags and clip to $max characters. */
    private function clip(string $text, int $max = self::ABSTRACT_MAX): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, $max);
    }
}
