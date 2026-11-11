<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * The identity-link rules DRE Linked Data applies, for the `sameAs` this
 * module emits when that module is absent or older than 0.3.0.
 *
 * The authority table is config/identity-authorities.php, a byte-identical
 * copy of DRE Linked Data's; the DOI rule below is a port of that module's
 * `IdentityLinks::doi()` and the IRI encoding of its `Rdf\Iri`. Both modules
 * run tests/Fixture/identity-links.json (the same cases, pinned by hash), so a
 * record names the same targets whichever module is active.
 */
final class IdentityAuthorities
{
    /** @var array<string,array{pattern:string,canonical:string}>|null */
    private static ?array $table = null;

    /**
     * A stored URI as its authority's canonical IRI, or null when it is no
     * listed authority's.
     */
    public static function authorityIri(string $uri): ?string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return null;
        }
        foreach (self::table() as $definition) {
            if (preg_match($definition['pattern'], $uri)) {
                return self::iri((string) preg_replace($definition['pattern'], $definition['canonical'], $uri));
            }
        }
        return null;
    }

    /**
     * A DOI stored bare (`10.1017/…`), with a `doi:` prefix or as a resolver
     * URL, as its https://doi.org/ IRI; null when the value is no DOI.
     *
     * The DOI is percent-decoded and `%`, `#` and `?` encoded again, so a
     * DOI containing them never turns into a fragment or a query. A resolver
     * URL's own query and fragment are not part of the DOI and are dropped.
     */
    public static function doiIri(string $value): ?string
    {
        if (!preg_match('~^(?:(https?://(?:dx\.)?doi\.org/)|doi:\s*)?(10\.[0-9]{4,9}/\S+)$~i', trim($value), $match)) {
            return null;
        }
        $encoded = $match[2];
        if ($match[1] !== '') {
            $encoded = (string) preg_replace('~[?#].*$~s', '', $encoded);
            if (!preg_match('~^10\.[0-9]{4,9}/.~', $encoded)) {
                return null;
            }
        }
        $doi = rawurldecode($encoded);
        return self::iri('https://doi.org/' . strtr($doi, ['%' => '%25', '#' => '%23', '?' => '%3F']));
    }

    /**
     * The IRI as DRE Linked Data serialises it: every byte that is not valid
     * UTF-8, and the characters N-Triples forbids inside `<…>`, percent-encoded.
     */
    private static function iri(string $value): string
    {
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) preg_replace_callback(
                '~([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
                . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
                . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)~s',
                static fn (array $match): string => isset($match[2])
                    ? sprintf('%%%02X', ord($match[2]))
                    : $match[1],
                $value
            );
        }
        return (string) preg_replace_callback(
            '~[\x00-\x20<>"{}|^`\\\\]~',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $value
        );
    }

    /** @return array<string,array{pattern:string,canonical:string}> */
    private static function table(): array
    {
        if (self::$table === null) {
            $table = [];
            /** @var mixed $authorities */
            $authorities = require dirname(__DIR__, 2) . '/config/identity-authorities.php';
            foreach (is_array($authorities) ? $authorities : [] as $name => $definition) {
                if (is_array($definition) && is_string($definition['pattern'] ?? null) && is_string($definition['canonical'] ?? null)) {
                    $table[(string) $name] = ['pattern' => $definition['pattern'], 'canonical' => $definition['canonical']];
                }
            }
            self::$table = $table;
        }
        return self::$table;
    }
}
