<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * FAIR Signposting (signposting.org/FAIR, level 1) for a record page: the
 * identifier to cite, the machine-readable descriptions of the record, what
 * kind of thing the page is about, its authors, its licence, its public files
 * and, for a file, the record it belongs to. F-UJI and other FAIR assessors
 * read these; search engines ignore them.
 *
 * The same set goes out twice: as <link> elements in the page head and as an
 * HTTP `Link` header, which is all many harvesters read.
 *
 * `cite-as` is the record's persistent identifier — its DRE Linked Data URI,
 * else the page — never a DOI, which names the publisher's version. The RDF
 * descriptions are DRE Linked Data's documents (`/resource/{id}.ttl`, `.nt`,
 * `.jsonld`) when that module is installed; without it, Omeka's own API
 * representation stands in, as before. Item pages, item-set landing pages and
 * media pages are signposted alike.
 */
final class Signposting
{
    use ResourceValueReader;

    /** The serialisations offered at /cite, as the media types Signposting announces. */
    private const DESCRIBED_BY = [
        'csljson' => 'application/vnd.citationstyles.csl+json',
        'bibtex'  => 'application/x-bibtex',
        'ris'     => 'application/x-research-info-systems',
    ];

    /** Where a work's authors are, preferred first — the order the JSON-LD `author` reads. */
    private const AUTHOR_TERMS = ['bibo:authorList', 'dcterms:creator', 'marcrel:aut', 'marcrel:cre', 'marcrel:hst', 'marcrel:spk'];

    /** Public files linked as `item`; a record with hundreds of scans links the first few. */
    private const MAX_ITEMS = 10;

    /** Authors linked; a work with a long author list links the first ones. */
    private const MAX_AUTHORS = 20;

    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly LinkedDataIdentity $identity,
    ) {
    }

    /**
     * @param string      $canonical  the record page
     * @param string      $origin     scheme and host the /cite downloads are served from
     * @param bool        $citations  whether the /cite downloads are on
     * @param string|null $collection the page of the record a media file belongs to
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    public function links(
        AbstractResourceEntityRepresentation $resource,
        string $canonical,
        string $origin,
        bool $citations,
        ?string $collection = null
    ): array {
        $links = [['rel' => 'cite-as', 'href' => $this->identity->uri($resource) ?? $canonical]];

        if ($citations && $resource instanceof ItemRepresentation) {
            $origin = rtrim($origin, '/');
            foreach (self::DESCRIBED_BY as $format => $mediaType) {
                $links[] = [
                    'rel'  => 'describedby',
                    'href' => $origin . '/cite/' . $resource->id() . '/' . $format,
                    'type' => $mediaType,
                ];
            }
        }

        $documents = $this->identity->documents($resource);
        foreach ($documents as $document) {
            $links[] = ['rel' => 'describedby', 'href' => $document['url'], 'type' => $document['type']];
        }
        if (!$documents) {
            try {
                $links[] = ['rel' => 'describedby', 'href' => $resource->apiUrl(), 'type' => 'application/ld+json'];
            } catch (\Throwable $e) {
                // no API URL
            }
        }

        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/' . $this->schemaType($resource)];
        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/AboutPage'];

        if ($resource instanceof ItemRepresentation) {
            foreach ($this->authors($resource) as $author) {
                $links[] = ['rel' => 'author', 'href' => $author];
            }
        }

        // rel=license must point at the licence itself: a licence known only
        // by its name gets no link.
        $licence = Licence::of($resource);
        if ($licence !== null && $licence->url !== null) {
            $links[] = ['rel' => 'license', 'href' => $licence->url];
        }

        foreach ($this->files($resource) as $file) {
            $links[] = ['rel' => 'item', 'href' => $file['url'], 'type' => $file['type']];
        }

        if ($resource instanceof MediaRepresentation && $collection !== null && $collection !== '') {
            $links[] = ['rel' => 'collection', 'href' => $collection];
        }

        return $links;
    }

    /**
     * The links as one RFC 8288 `Link` header value. Anything a header may not
     * carry is percent-encoded in the target, so the value is always valid.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    public static function header(array $links): string
    {
        $parts = [];
        foreach ($links as $link) {
            $target = preg_replace_callback(
                '/[^\x21-\x7E]|[<>"]/',
                static fn (array $match): string => rawurlencode($match[0]),
                $link['href']
            );
            $part = '<' . $target . '>; rel="' . self::token($link['rel']) . '"';
            if (isset($link['type']) && $link['type'] !== '') {
                $part .= '; type="' . self::token($link['type']) . '"';
            }
            $parts[] = $part;
        }
        return implode(', ', $parts);
    }

    /** What the page is about, as a schema.org type. */
    private function schemaType(AbstractResourceEntityRepresentation $resource): string
    {
        return match (true) {
            $resource instanceof ItemSetRepresentation => 'Collection',
            $resource instanceof MediaRepresentation => 'MediaObject',
            default => $this->structuredData->typeFor($resource),
        };
    }

    /**
     * The work's authors, by the identifier a FAIR assessor can follow: the
     * person's ORCID iD when the record has one, else its Linked Data URI.
     * An author with neither (a name only) is not linked.
     *
     * @return string[]
     */
    private function authors(ItemRepresentation $item): array
    {
        foreach (self::AUTHOR_TERMS as $term) {
            $values = $item->value($term, ['all' => true]);
            if (!$values) {
                continue;
            }
            $out = [];
            foreach ($values as $value) {
                $linked = $value instanceof ValueRepresentation ? $value->valueResource() : null;
                if (!$linked) {
                    continue;
                }
                $href = self::orcidOf($this->structuredData->sameAsFor($linked)) ?? $this->identity->uri($linked);
                if ($href !== null) {
                    $out[$href] = $href;
                }
                if (count($out) >= self::MAX_AUTHORS) {
                    break;
                }
            }
            return array_values($out); // the first populated role is the authors
        }
        return [];
    }

    /**
     * The public files of an item, or a media page's own file.
     *
     * @return array<int,array{url:string,type:string}>
     */
    private function files(AbstractResourceEntityRepresentation $resource): array
    {
        $media = match (true) {
            $resource instanceof ItemRepresentation => $resource->media(),
            $resource instanceof MediaRepresentation => [$resource],
            default => [],
        };
        $out = [];
        foreach ($media as $file) {
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
            if (method_exists($file, 'isPublic') && !$file->isPublic()) {
                continue;
            }
            $url = method_exists($file, 'originalUrl') ? $file->originalUrl() : null;
            $type = method_exists($file, 'mediaType') ? $file->mediaType() : null;
            if ($url && $type) {
                $out[] = ['url' => (string) $url, 'type' => (string) $type];
            }
        }
        return $out;
    }

    /** A relation type or media type, reduced to the characters either may hold. */
    private static function token(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9!#$&^_.+\/-]/', '', $value);
    }
}
