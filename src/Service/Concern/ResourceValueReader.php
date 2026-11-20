<?php
declare(strict_types=1);

namespace DRESeo\Service\Concern;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\IdentityAuthorities;
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
 * bibo:doi normalisation, the ISBN properties, the identity links that become
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

    /**
     * Where a record's description is, preferred first, for the citation
     * abstract, DC.description, the JSON-LD `description` and the page's meta
     * description alike. The abstract leads: on a publication
     * dcterms:description is EPrints' free-text note, on a research item the
     * setting, and either would describe the record worse than its abstract.
     */
    private const DESCRIPTION_TERMS = ['bibo:abstract', 'dcterms:abstract', 'dcterms:description'];

    /**
     * Where a work's editors are, preferred first: citation_editor, the
     * citation's editors and the JSON-LD `editor`. Authors are
     * {@see \DRESeo\Service\CitationKind::authorTerms()}, which depends on the
     * kind.
     */
    private const EDITOR_TERMS = ['bibo:editorList', 'marcrel:edt'];

    /** Abstracts are clipped to this many characters before being emitted. */
    private const ABSTRACT_MAX = 5000;

    /**
     * ISBN properties, preferred first. ERef and EPub records carry
     * bibo:isbn13; bibo:isbn is the generic term a hand-entered record may use.
     */
    private const ISBN_TERMS = ['bibo:isbn13', 'bibo:isbn', 'bibo:isbn10'];

    /**
     * First non-empty literal/label across the given terms, in order, tags
     * stripped. A linked record reads as its title: Omeka renders a linked
     * value as the record's URL, which no caller wants as text.
     *
     * @param string[] $terms
     */
    private function firstString(AbstractResourceEntityRepresentation $resource, array $terms): ?string
    {
        foreach ($terms as $term) {
            $value = $resource->value($term);
            if ($value instanceof ValueRepresentation) {
                $linked = $value->valueResource();
                $text = trim(strip_tags($linked ? (string) $linked->displayTitle() : (string) $value));
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
     * The people (or organisations) a record names under the first of $terms
     * that holds any: each linked record once, by its id, and each literal
     * name once. Two different records that share a name stay two people,
     * and one record entered twice counts once. The citation, the Highwire
     * and Dublin Core tags, the JSON-LD and the generated description all read
     * this list, so they name the same people.
     *
     * @param string[] $terms
     * @return list<array{label:string,linked:?AbstractResourceEntityRepresentation}>
     */
    private function agentsOf(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        foreach ($terms as $term) {
            $out = [];
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $linked = $value->valueResource();
                $label = $linked ? trim((string) $linked->displayTitle()) : trim(strip_tags((string) $value));
                if ($label === '') {
                    continue;
                }
                $out[$linked ? 'record:' . $linked->id() : 'name:' . $label] = ['label' => $label, 'linked' => $linked];
            }
            if ($out !== []) {
                return array_values($out);
            }
        }
        return [];
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
     *
     * Since MongoDB2OmekaS of 27 October 2026 the id is the label of a link to
     * the repository permalink, read from the stored value (which a URI value
     * renders as too); a link without a label names its id by the permalink.
     * Older records carry the id as a literal.
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
            $text = trim(strip_tags($value->type() === 'uri' ? (string) $value->value() : (string) $value));
            if (preg_match('/^e(?:ref|pub)-\S+$/i', $text)) {
                return $text;
            }
            if (
                $value->type() === 'uri'
                && preg_match('~^https?://(eref|epub)\.uni-bayreuth\.de/id/eprint/(\d+)~i', (string) $value->uri(), $m)
            ) {
                return strtolower($m[1]) . '-' . $m[2];
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
     * Every URI a property holds: URI-typed values by their URI, and literals
     * that are themselves http(s) URLs.
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
            if (preg_match('#\Ahttps?://\S+\z#i', $uri)) {
                $out[$uri] = $uri;
            }
        }
        return array_values($out);
    }

    /**
     * The record's identity links — `sameAs` in JSON-LD — exactly as DRE
     * Linked Data states them, for when that module does not say: each URI
     * value of dcterms:identifier that is a listed authority's
     * ({@see IdentityAuthorities}: GND for people, Wikidata for places and
     * institutions, LCSH and Wikidata for subject headings), in its canonical
     * form, then each bibo:doi as its https://doi.org/ IRI. Public values
     * only, as that module reads them.
     *
     * A literal is never a link, whatever it spells: a bare "Q42" in a research
     * item's identifiers is as likely a questionnaire number as a Wikidata id.
     * The RDSpace handle is no link either: its "rdspace-ubt" prefix is not
     * registered with the Handle System, and the dataset's DOI already names
     * the deposit. WissKI links are retired and deliberately never read.
     *
     * @return string[]
     */
    private function identityUris(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        foreach (['dcterms:identifier', 'bibo:doi'] as $term) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (
                    !$value instanceof ValueRepresentation
                    || $value->valueResource()
                    || !$value->isPublic()
                ) {
                    continue;
                }
                $uri = trim((string) ($value->uri() ?? ''));
                $iri = $term === 'bibo:doi'
                    ? IdentityAuthorities::doiIri($uri !== '' ? $uri : (string) $value->value())
                    : ($uri !== '' ? IdentityAuthorities::authorityIri($uri) : null);
                if ($iri !== null) {
                    $out[$iri] = $iri;
                }
            }
        }
        return array_values($out);
    }

    /**
     * The ORCID iD among the IRIs, if any.
     *
     * @param array<mixed> $iris
     */
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
            // One date: an interval gives only what its two ends share.
            return IssuedDate::parse($raw)->asSingleDate()->iso();
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
     * How many pages a book, report or thesis has: bibo:numPages, else the
     * extent catalogued in bibo:pages ("XIV, 310", "18 S.").
     */
    private function pageCount(AbstractResourceEntityRepresentation $resource): ?string
    {
        $count = $this->firstString($resource, ['bibo:numPages']);
        if ($count !== null && preg_match('/^\d+$/', $count)) {
            return $count;
        }
        return CitationRecord::pageCount($this->firstString($resource, ['bibo:pages']));
    }

    /** Whitespace-normalise, strip tags and clip to $max characters. */
    private function clip(string $text, int $max = self::ABSTRACT_MAX): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, $max);
    }
}
