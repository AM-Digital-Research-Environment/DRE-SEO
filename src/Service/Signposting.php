<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * FAIR Signposting (signposting.org/FAIR, level 1) for an item page: the
 * identifier to cite, the machine-readable descriptions of the record, what
 * kind of thing the page is about, its licence and its public files. F-UJI and
 * other FAIR assessors read these; search engines ignore them.
 *
 * The same set goes out twice: as <link> elements in the page head and as an
 * HTTP `Link` header, which is all many harvesters read.
 *
 * The RDF descriptions are DRE Linked Data's documents (`/resource/{id}.ttl`,
 * `.nt`, `.jsonld`) when that module is installed; without it, Omeka's own
 * API representation stands in, as before.
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

    /** Public files linked as `item`; a record with hundreds of scans links the first few. */
    private const MAX_ITEMS = 10;

    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly LinkedDataIdentity $identity,
    ) {
    }

    /**
     * @param string $canonical the record page
     * @param string $origin    scheme and host the /cite downloads are served from
     * @param bool   $citations whether the /cite downloads are on
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    public function links(ItemRepresentation $item, string $canonical, string $origin, bool $citations): array
    {
        $doi = $this->doi($item);
        $links = [['rel' => 'cite-as', 'href' => $doi !== null ? 'https://doi.org/' . $doi : $canonical]];

        if ($citations) {
            $origin = rtrim($origin, '/');
            foreach (self::DESCRIBED_BY as $format => $mediaType) {
                $links[] = [
                    'rel'  => 'describedby',
                    'href' => $origin . '/cite/' . $item->id() . '/' . $format,
                    'type' => $mediaType,
                ];
            }
        }

        $documents = $this->identity->documents($item);
        foreach ($documents as $document) {
            $links[] = ['rel' => 'describedby', 'href' => $document['url'], 'type' => $document['type']];
        }
        if (!$documents) {
            try {
                $links[] = ['rel' => 'describedby', 'href' => $item->apiUrl(), 'type' => 'application/ld+json'];
            } catch (\Throwable $e) {
                // no API URL
            }
        }

        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/' . $this->structuredData->typeFor($item)];
        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/AboutPage'];

        $license = $this->uris($item, 'dcterms:license')[0] ?? null;
        if ($license !== null) {
            $links[] = ['rel' => 'license', 'href' => $license];
        }

        $files = 0;
        foreach ($item->media() as $media) {
            if ($files >= self::MAX_ITEMS || (method_exists($media, 'isPublic') && !$media->isPublic())) {
                continue;
            }
            $url = method_exists($media, 'originalUrl') ? $media->originalUrl() : null;
            $type = method_exists($media, 'mediaType') ? $media->mediaType() : null;
            if ($url && $type) {
                $links[] = ['rel' => 'item', 'href' => $url, 'type' => $type];
                $files++;
            }
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

    /** A relation type or media type, reduced to the characters either may hold. */
    private static function token(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9!#$&^_.+\/-]/', '', $value);
    }
}
