<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * Cache validators for a generated download: a strong ETag over the body and
 * the record's last change as Last-Modified, and whether a conditional request
 * already holds the current copy (RFC 9110 §13: If-None-Match wins over
 * If-Modified-Since).
 */
final class HttpCache
{
    /** How long a shared cache or a browser may reuse a /cite download without asking. */
    public const MAX_AGE = 300;

    public static function etag(string $body): string
    {
        return '"' . substr(hash('sha256', $body), 0, 32) . '"';
    }

    public static function lastModified(?\DateTimeInterface $modified): ?string
    {
        if ($modified === null) {
            return null;
        }
        return \DateTimeImmutable::createFromInterface($modified)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s') . ' GMT';
    }

    /**
     * Whether the client's copy is current, so a 304 answers the request.
     *
     * @param string|null $ifNoneMatch     the request's If-None-Match header value
     * @param string|null $ifModifiedSince the request's If-Modified-Since header value
     */
    public static function notModified(
        string $etag,
        ?\DateTimeInterface $modified,
        ?string $ifNoneMatch,
        ?string $ifModifiedSince
    ): bool {
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                    return true;
                }
            }
            return false;
        }
        if ($modified === null || $ifModifiedSince === null || trim($ifModifiedSince) === '') {
            return false;
        }
        $since = strtotime($ifModifiedSince);
        return $since !== false && $modified->getTimestamp() <= $since;
    }
}
