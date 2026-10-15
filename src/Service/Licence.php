<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * A record's licence, resolved once for the JSON-LD `license` and the
 * Signposting `license` link, so the two cannot disagree.
 *
 * AMIRA links licences as authority items (template 6, "CC-BY-NC-SA-4.0",
 * dcterms:type "License") rather than storing their URL. A licence item
 * resolves to a URL when it carries one from a licence registry
 * (creativecommons.org, rightsstatements.org, opensource.org) in an
 * identifier-like property, else when its label is a well-known Creative
 * Commons licence; failing both it is named, not linked — a CreativeWork in
 * the JSON-LD and no Signposting link, since `rel=license` must point at the
 * licence itself. A URI value is a URL as stored; a literal is a URL when it
 * is one or names a known licence, else free text.
 */
final class Licence
{
    /** The properties a licence is read from, preferred first. */
    private const TERMS = ['dcterms:license', 'dcterms:rights'];

    /** Where a licence item may keep its URL. */
    private const URL_TERMS = [
        'dcterms:identifier', 'dcterms:source', 'schema:url', 'foaf:page', 'foaf:homepage',
        'dcterms:references', 'dcterms:relation', 'rdfs:seeAlso', 'owl:sameAs', 'schema:sameAs', 'fabio:hasURL',
    ];

    /** Hosts whose URLs are licences. */
    private const REGISTRIES = ['creativecommons.org', 'rightsstatements.org', 'opensource.org'];

    /** The Creative Commons licence elements in their URL order. */
    private const CC_COMBINATIONS = ['by', 'by-sa', 'by-nc', 'by-nc-sa', 'by-nc-nd', 'by-nd'];

    private function __construct(
        /** The licence URL, when it resolves to one. */
        public readonly ?string $url,
        /** The licence's name, for a licence that does not resolve. */
        public readonly ?string $name,
        /** The licence's authority item, when it is linked. */
        public readonly ?AbstractResourceEntityRepresentation $item,
    ) {
    }

    public static function of(AbstractResourceEntityRepresentation $resource): ?self
    {
        foreach (self::TERMS as $term) {
            $value = $resource->value($term);
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            if ($linked) {
                $label = trim((string) $linked->displayTitle());
                $url = self::registryUrl($linked) ?? self::urlForLabel($label);
                if ($url === null) {
                    foreach ($linked->value('dcterms:alternative', ['all' => true]) as $alternative) {
                        $url = self::urlForLabel(trim(strip_tags((string) $alternative)));
                        if ($url !== null) {
                            break;
                        }
                    }
                }
                if ($url !== null || $label !== '') {
                    return new self($url, $label !== '' ? $label : null, $linked);
                }
                continue;
            }
            $uri = trim((string) ($value->uri() ?? ''));
            if ($uri !== '' && preg_match('#\Ahttps?://\S+\z#i', $uri)) {
                return new self($uri, trim(strip_tags((string) $value)) ?: null, null);
            }
            $text = trim(strip_tags((string) $value));
            if ($text === '') {
                continue;
            }
            if (preg_match('#\Ahttps?://\S+\z#i', $text)) {
                return new self($text, null, null);
            }
            return new self(self::urlForLabel($text), $text, null);
        }
        return null;
    }

    /**
     * The canonical creativecommons.org URL of a licence named by its short
     * form ("CC-BY-NC-SA-4.0", "CC BY 3.0", "CC0-1.0") or its full name
     * ("Creative Commons Attribution-NonCommercial-ShareAlike 4.0
     * International"); null for anything else.
     */
    public static function urlForLabel(string $label): ?string
    {
        $key = strtoupper(trim($label));
        $key = str_replace(
            ['CREATIVE COMMONS', 'ATTRIBUTION', 'NONCOMMERCIAL', 'NON-COMMERCIAL', 'SHAREALIKE', 'SHARE-ALIKE', 'NODERIVATIVES', 'NODERIVS', 'NO DERIVATIVES'],
            ['CC', 'BY', 'NC', 'NC', 'SA', 'SA', 'ND', 'ND', 'ND'],
            $key
        );
        $key = preg_replace('/\s+(INTERNATIONAL|UNPORTED|LICEN[CS]E|PUBLIC DOMAIN DEDICATION|UNIVERSAL)\b/', '', $key) ?? $key;
        $key = trim((string) preg_replace('/[\s_]+/', '-', $key), '-');

        if (preg_match('/\ACC-?0(?:-?1\.0)?\z/', $key) || $key === 'CC-ZERO') {
            return 'https://creativecommons.org/publicdomain/zero/1.0/';
        }
        if (preg_match('/\ACC-(BY(?:-(?:NC|SA|ND))*)-?([34]\.0)\z/', $key, $m)) {
            $elements = strtolower($m[1]);
            if (in_array($elements, self::CC_COMBINATIONS, true)) {
                return 'https://creativecommons.org/licenses/' . $elements . '/' . $m[2] . '/';
            }
        }
        return null;
    }

    /** A licence registry URL among the licence item's own identifiers. */
    private static function registryUrl(AbstractResourceEntityRepresentation $item): ?string
    {
        foreach (self::URL_TERMS as $term) {
            foreach ($item->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation || $value->valueResource()) {
                    continue;
                }
                $url = trim((string) ($value->uri() ?: strip_tags((string) $value)));
                if (!preg_match('#\Ahttps?://\S+\z#i', $url)) {
                    continue;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                foreach (self::REGISTRIES as $registry) {
                    if ($host === $registry || str_ends_with($host, '.' . $registry)) {
                        return $url;
                    }
                }
            }
        }
        return null;
    }
}
