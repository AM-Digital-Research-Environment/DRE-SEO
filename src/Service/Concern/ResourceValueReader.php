<?php
declare(strict_types=1);

namespace DRESeo\Service\Concern;

use DRESeo\Service\Citation\CitationRecord;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Shared leaf value-readers for the citation services.
 *
 * These are the DRE's field conventions in one place: linked-resource labels
 * via displayTitle, the dcterms:subject keyword set, the dre:id accession, the
 * bibo:doi normalisation and the public-PDF lookup.
 *
 * {@see \DRESeo\Service\CitationMeta} and {@see \DRESeo\Service\StructuredData}
 * still carry their own private copies of the leaf readers; folding them onto
 * this trait is a mechanical follow-up, deliberately kept out of the diff that
 * introduced the citation stack.
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
     * The resource's citation page range, read from bibo:pageStart/pageEnd.
     * The join rule itself belongs to the record, so a page range reads the
     * same whether it came from a resource or from a built citation.
     */
    private function pageRange(AbstractResourceEntityRepresentation $resource): ?string
    {
        return CitationRecord::joinPages(
            $this->firstString($resource, ['bibo:pageStart']),
            $this->firstString($resource, ['bibo:pageEnd'])
        );
    }

    /** Whitespace-normalise, strip tags and clip to $max characters. */
    private function clip(string $text, int $max = self::ABSTRACT_MAX): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, $max);
    }
}
