<?php
declare(strict_types=1);

namespace DRESeo\Service\Sitemap;

/**
 * A rendered sitemap document and the moment it was generated.
 *
 * Returned by every build, whether the cache served it or the generator
 * built it just now, so the controller's Last-Modified header is the
 * document's own age rather than something read back off a shared service
 * after the call.
 */
final class SitemapDocument
{
    public function __construct(
        public readonly string $xml,
        /** Unix timestamp: the cache file's mtime on a hit, the build moment otherwise. */
        public readonly int $lastModified,
    ) {
    }

    /** The value for an HTTP Last-Modified header. */
    public function lastModifiedHeader(): string
    {
        return gmdate('D, d M Y H:i:s', $this->lastModified) . ' GMT';
    }
}
