<?php
declare(strict_types=1);

namespace DRESeo\Service\Sitemap;

use Laminas\Log\LoggerInterface;

/**
 * The sitemap's TTL file cache (files/dre-seo-cache), with atomic
 * publication, generation invalidation and a per-document build lock.
 *
 * Caching a sitemap is an optimisation, never a correctness requirement, so
 * the failure modes are designed as carefully as the hits:
 *
 * - Generation stamp. clear() writes a fresh random token to `generation`,
 *   and each cached document records, in a `.generation` sidecar, the token
 *   it was built under; a document whose sidecar no longer matches is a miss.
 *   That makes every clear O(1) — a bulk import that clears once per saved
 *   item rewrites one small file each time, and deletes the cached documents
 *   only on the first clear of the request — and it stops a stale writer: a
 *   build that started before a clear publishes nothing, because the
 *   generation it read is no longer current when it comes to store.
 * - Build lock. One request at a time rebuilds a document; the others wait
 *   (at most five seconds, then the request fails and the controller answers
 *   503) and then read what it stored, so an expired sitemap under crawler
 *   load is rebuilt once, not by every request at once.
 * - Atomic publication. A document is written to a temporary file and renamed
 *   into place, so no reader sees half of one.
 * - Failure. A build that throws (the database is down) is not cached. A
 *   document of the current generation that expired less than five minutes
 *   ago is served instead, and logged; otherwise the error propagates.
 *
 * A cache directory that cannot be created or written is logged and the
 * document built live: the sitemap still works, only slower.
 */
final class XmlCache
{
    /** Seconds a request waits for another to finish rebuilding a document. */
    private const LOCK_WAIT = 5;

    /** Seconds past its lifetime a document may still stand in for a failed rebuild. */
    private const STALE_GRACE = 300;

    /** Whether this request already deleted the documents since it last stored one. */
    private bool $cleared = false;

    public function __construct(
        private readonly ?string $directory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param callable():string $build */
    public function remember(string $key, int $ttl, callable $build): SitemapDocument
    {
        if ($this->directory === null || $ttl <= 0) {
            return new SitemapDocument($build(), time());
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger->warn('DRESeo: failed to create the sitemap cache directory.', [
                'cache_dir' => $this->directory,
            ]);
            return new SitemapDocument($build(), time());
        }
        $file = $this->path($key);
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            $this->logger->warn('DRESeo: failed to open a sitemap cache lock; generating live output.', [
                'file' => $file . '.lock',
            ]);
            return new SitemapDocument($build(), time());
        }
        try {
            $deadline = microtime(true) + self::LOCK_WAIT;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Sitemap rebuild is already running.');
                }
                usleep(50000);
            }
            $generation = $this->generation();
            clearstatcache(true, $file);
            $mtime = is_file($file) ? filemtime($file) : false;
            $cached = $mtime !== false ? @file_get_contents($file) : false;
            if ($generation !== $this->generation() || @file_get_contents($file . '.generation') !== $generation) {
                $cached = false;
            }
            if ($cached !== false && $cached !== '' && time() - $mtime < $ttl) {
                return new SitemapDocument($cached, $mtime);
            }
            try {
                $xml = $build();
            } catch (\Throwable $error) {
                if (
                    $cached !== false && $cached !== '' && $mtime !== false
                    && time() - $mtime < $ttl + self::STALE_GRACE && $generation === $this->generation()
                ) {
                    $this->logger->warn('DRESeo: serving the expired sitemap after a generation failure.', [
                        'file' => $file,
                        'exception' => $error,
                    ]);
                    return new SitemapDocument($cached, $mtime);
                }
                throw $error;
            }
            $this->publish($file, $xml, $generation);
            return new SitemapDocument($xml, time());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Invalidate every cached document: a new generation, and on the first
     * clear since this request last stored a document, the documents deleted.
     * Failures are logged, not thrown — a save that triggered the clear must
     * not fail over it.
     */
    public function clear(): void
    {
        if ($this->directory === null || !is_dir($this->directory)) {
            return;
        }
        $guard = $this->lockGeneration();
        if ($guard === null) {
            return;
        }
        try {
            if (@file_put_contents($this->directory . '/generation', bin2hex(random_bytes(16))) === false) {
                $this->logger->warn('DRESeo: failed to invalidate the sitemap cache generation.', [
                    'cache_dir' => $this->directory,
                ]);
            }
            if (!$this->cleared) {
                $files = glob($this->directory . '/*.xml');
                if ($files === false) {
                    $this->logger->warn('DRESeo: failed to list sitemap cache files.', [
                        'cache_dir' => $this->directory,
                    ]);
                }
                foreach ($files ?: [] as $file) {
                    if (!@unlink($file)) {
                        $this->logger->warn('DRESeo: failed to remove a sitemap cache file.', [
                            'file' => $file,
                        ]);
                    }
                    @unlink($file . '.generation');
                }
            }
            $this->cleared = true;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /**
     * Clear the cache and remove its directory, whatever else is in it
     * (uninstall). A directory left behind is logged: the module is going, but
     * a stale files/dre-seo-cache would be read again by a reinstall.
     */
    public function destroy(): void
    {
        $this->cleared = false;
        $this->clear();
        if ($this->directory === null || !is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (!@rmdir($this->directory)) {
            $this->logger->warn('DRESeo: failed to remove the sitemap cache directory.', [
                'cache_dir' => $this->directory,
            ]);
        }
    }

    /**
     * Store a document built under $generation — unless a clear made another
     * generation current while it was being built, in which case it is
     * already out of date and nothing is stored.
     */
    private function publish(string $file, string $xml, string $generation): void
    {
        $guard = $this->lockGeneration();
        if ($guard === null) {
            return;
        }
        try {
            if ($generation !== $this->generation()) {
                return;
            }
            $temporary = @tempnam((string) $this->directory, 'sitemap-');
            if ($temporary === false) {
                $this->logger->warn('DRESeo: failed to write a sitemap cache file.', ['file' => $file]);
                return;
            }
            try {
                if (@file_put_contents($temporary, $xml) === strlen($xml) && @rename($temporary, $file)) {
                    @file_put_contents($file . '.generation', $generation);
                    $this->cleared = false;
                } else {
                    $this->logger->warn('DRESeo: failed to write a sitemap cache file.', ['file' => $file]);
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /**
     * The lock that orders clears against publications, or null (logged) when
     * it cannot be had.
     *
     * @return resource|null
     */
    private function lockGeneration()
    {
        $guard = @fopen($this->directory . '/generation.lock', 'c');
        if ($guard === false || !flock($guard, LOCK_EX)) {
            if ($guard !== false) {
                fclose($guard);
            }
            $this->logger->warn('DRESeo: failed to lock the sitemap cache generation.', [
                'cache_dir' => $this->directory,
            ]);
            return null;
        }
        return $guard;
    }

    private function generation(): string
    {
        return (string) @file_get_contents($this->directory . '/generation');
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $key) . '.xml';
    }
}
