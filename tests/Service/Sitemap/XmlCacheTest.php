<?php
declare(strict_types=1);

namespace DRESeo\Test\Service\Sitemap;

use DRESeo\Service\Sitemap\XmlCache;
use DRESeo\Test\Double\MemoryLogger;
use PHPUnit\Framework\TestCase;

/**
 * The TTL file cache. Caching a sitemap is an optimisation, never a
 * correctness requirement, so the failure modes matter as much as the hits.
 */
final class XmlCacheTest extends TestCase
{
    private string $dir;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dre-seo-cache-test-' . bin2hex(random_bytes(6));
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function cache(): XmlCache
    {
        return new XmlCache($this->dir, $this->logger);
    }

    public function testFirstCallBuildsAndSecondCallHitsTheCache(): void
    {
        $cache = $this->cache();
        $builds = 0;
        $build = function () use (&$builds): string {
            $builds++;
            return '<xml>' . $builds . '</xml>';
        };

        $first = $cache->remember('site-1-pages', 3600, $build);
        $second = $this->cache()->remember('site-1-pages', 3600, $build);

        $this->assertSame('<xml>1</xml>', $first->xml);
        $this->assertSame('<xml>1</xml>', $second->xml, 'served from cache, by another request too');
        $this->assertSame(1, $builds);
        $this->assertSame([], $this->logger->records);
    }

    public function testKeysAreIndependent(): void
    {
        $cache = $this->cache();
        $cache->remember('site-1-pages', 3600, static fn (): string => 'one');
        $cache->remember('site-2-pages', 3600, static fn (): string => 'two');

        $this->assertSame('one', $cache->remember('site-1-pages', 3600, static fn (): string => 'rebuilt')->xml);
        $this->assertSame('two', $cache->remember('site-2-pages', 3600, static fn (): string => 'rebuilt')->xml);
    }

    public function testExpiredEntriesAreRebuilt(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 3600, static fn (): string => 'stale');
        touch($this->dir . '/k.xml', time() - 7200);

        $this->assertSame('fresh', $cache->remember('k', 3600, static fn (): string => 'fresh')->xml);
    }

    public function testZeroTtlDisablesCachingEntirely(): void
    {
        $document = $this->cache()->remember('k', 0, static fn (): string => 'live');

        $this->assertSame('live', $document->xml);
        $this->assertFileDoesNotExist($this->dir . '/k.xml');
    }

    public function testNoDirectoryStillServesLiveDocuments(): void
    {
        $cache = new XmlCache(null, $this->logger);
        $this->assertSame('live', $cache->remember('k', 3600, static fn (): string => 'live')->xml);
        $cache->clear();
        $cache->destroy();
        $this->assertSame([], $this->logger->records);
    }

    /** A cache directory that cannot exist is logged, and the document built live. */
    public function testAnUnusableDirectoryIsLoggedAndTheDocumentBuiltLive(): void
    {
        file_put_contents($this->dir, 'a file where the directory should be');
        try {
            $document = $this->cache()->remember('k', 3600, static fn (): string => 'live');
        } finally {
            unlink($this->dir);
        }

        $this->assertSame('live', $document->xml);
        $this->assertCount(1, $this->logger->records['warn'] ?? []);
        $this->assertStringContainsString('cache directory', $this->logger->records['warn'][0]['message']);
    }

    public function testInvalidationDuringBuildCannotPublishObsoleteCache(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 3600, function (): string {
            // Another request saved a record, and cleared, mid-build.
            $this->cache()->clear();
            return 'obsolete';
        });
        $this->assertFileDoesNotExist($this->dir . '/k.xml');
        $this->assertSame('current', $cache->remember('k', 3600, static fn (): string => 'current')->xml);
    }

    public function testFailureDoesNotCacheEmptySitemap(): void
    {
        $cache = $this->cache();
        try {
            $cache->remember('k', 3600, static function (): string {
                throw new \RuntimeException('database unavailable');
            });
            $this->fail('The failure must reach the controller.');
        } catch (\RuntimeException $error) {
            $this->assertSame('database unavailable', $error->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/k.xml');
    }

    /** Within five minutes of expiry, the last good copy stands in for a failed rebuild, and says so. */
    public function testARecentlyExpiredCopyStandsInForAFailedRebuild(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 3600, static fn (): string => 'last good');
        touch($this->dir . '/k.xml', time() - 3700);

        $document = $cache->remember('k', 3600, static function (): string {
            throw new \RuntimeException('database unavailable');
        });

        $this->assertSame('last good', $document->xml);
        $this->assertCount(1, $this->logger->records['warn'] ?? []);

        // Much older than that, the failure stands.
        touch($this->dir . '/k.xml', time() - 7200);
        $this->expectException(\RuntimeException::class);
        $cache->remember('k', 3600, static function (): string {
            throw new \RuntimeException('database unavailable');
        });
    }

    /** A cleared copy never stands in, however recent: it predates a change. */
    public function testAClearedCopyNeverStandsInForAFailedRebuild(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 3600, static fn (): string => 'before the edit');
        $sidecar = (string) file_get_contents($this->dir . '/k.xml.generation');
        // The edit's clear, from another request. Say its deletions failed:
        // the document survives, but the generation has moved on.
        $this->cache()->clear();
        file_put_contents($this->dir . '/k.xml', 'before the edit');
        file_put_contents($this->dir . '/k.xml.generation', $sidecar);
        touch($this->dir . '/k.xml', time() - 3700);

        $this->expectException(\RuntimeException::class);
        $cache->remember('k', 3600, static function (): string {
            throw new \RuntimeException('database unavailable');
        });
    }

    public function testRepeatedInvalidationStillInvalidatesAnInterveningBuild(): void
    {
        $cache = $this->cache();
        $cache->remember('k', 3600, static fn (): string => 'first');
        $cache->clear();
        // Another request rebuilds between two saves of a bulk import.
        $this->cache()->remember('k', 3600, static fn (): string => 'between edits');
        $cache->clear();
        $this->assertSame('latest', $cache->remember('k', 3600, static fn (): string => 'latest')->xml);
    }

    public function testLastModifiedIsTheCacheMtimeOnAHit(): void
    {
        $cache = $this->cache();
        $built = $cache->remember('k', 3600, static fn (): string => 'cached');
        $this->assertEqualsWithDelta(time(), $built->lastModified, 2, 'a build is dated now');

        $written = time() - 120;
        touch($this->dir . '/k.xml', $written);

        $document = $cache->remember('k', 3600, static fn (): string => 'rebuilt');
        $this->assertSame('cached', $document->xml);
        $this->assertSame($written, $document->lastModified);
        $this->assertSame(gmdate('D, d M Y H:i:s', $written) . ' GMT', $document->lastModifiedHeader());
    }

    public function testClearRemovesEveryDocument(): void
    {
        $cache = $this->cache();
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->remember('b', 3600, static fn (): string => 'b');

        $cache->clear();

        $this->assertSame([], glob($this->dir . '/*.xml'));
    }

    /**
     * The API listener clears on every content change, so a bulk import would
     * otherwise run one glob-and-unlink cycle per saved item. The generation
     * stamp still changes on every clear.
     */
    public function testClearIsDebouncedWithinARequest(): void
    {
        $cache = $this->cache();
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->clear();

        // Simulate a document reappearing without going through the cache.
        file_put_contents($this->dir . '/a.xml', 'stale');
        $generation = file_get_contents($this->dir . '/generation');
        $cache->clear();
        $this->assertFileExists($this->dir . '/a.xml', 'second clear deletes nothing');
        $this->assertNotSame($generation, file_get_contents($this->dir . '/generation'), 'but invalidates all the same');

        // Writing through the cache re-arms the debounce.
        $cache->remember('b', 3600, static fn (): string => 'b');
        $cache->clear();
        $this->assertSame([], glob($this->dir . '/*.xml'));
    }

    public function testDestroyAlwaysSweepsAndRemovesTheDirectory(): void
    {
        $cache = $this->cache();
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->clear();          // arms the debounce
        file_put_contents($this->dir . '/a.xml', 'stale');

        $cache->destroy();        // uninstall must sweep regardless

        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertSame([], $this->logger->records);
    }
}
