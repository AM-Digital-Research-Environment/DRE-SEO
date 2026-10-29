<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * The robots.txt body: the module's fixed exclusions plus the instance's
 * crawl-control rules from `dre_seo.robots` (config/instance.config.php).
 *
 * The instance rules exist for query variants. Google's guidance on faceted
 * navigation is to disallow filter URLs that need not be indexed rather than
 * rely on noindex, because a noindex can only be read by crawling the page —
 * it spends the crawl budget it was meant to save. The pages themselves keep
 * their noindex for any crawler that ignores robots.txt, and the records they
 * list are reached through the sitemap.
 *
 * {@see allows()} answers "may a crawler fetch this path?" with Google's
 * precedence, so tests can prove that landing pages, pagination and every
 * sitemap URL stay crawlable under whatever the configuration says. Ported
 * from IWAC SEO; DRE keeps its staging switch, which closes the whole site.
 */
final class RobotsTxt
{
    /** Never useful to a crawler, whatever the instance. */
    private const CORE_DISALLOW = ['/admin/', '/login', '/logout', '/maintenance'];

    /** @var string[] */
    private readonly array $disallow;

    /** @var string[] */
    private readonly array $allow;

    /**
     * @param array<mixed> $disallow path patterns (`*` = any characters, `$` = end)
     * @param array<mixed> $allow    exceptions to $disallow, e.g. clean pagination
     */
    public function __construct(array $disallow = [], array $allow = [])
    {
        $this->disallow = array_values(array_unique(array_merge(self::CORE_DISALLOW, self::clean($disallow))));
        $this->allow = self::clean($allow);
    }

    /**
     * @param string|null $sitemapUrl the Sitemap: line, when the sitemap is served
     * @param bool        $closed     the staging switch: the whole site is
     *                                disallowed, and nothing else is said
     */
    public function render(?string $sitemapUrl, bool $closed = false): string
    {
        $lines = ['User-agent: *'];
        if ($closed) {
            $lines[] = 'Disallow: /';
        } else {
            // Allow lines first: Google and Bing pick the longest match
            // wherever it sits, but first-match parsers still exist.
            foreach ($this->allow as $path) {
                $lines[] = 'Allow: ' . $path;
            }
            foreach ($this->disallow as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
        }
        if ($sitemapUrl !== null && $sitemapUrl !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $sitemapUrl;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether a crawler may fetch $path (path plus query), by Google's rule:
     * the longest matching pattern wins, and Allow wins a tie.
     */
    public function allows(string $path): bool
    {
        $allow = self::longestMatch($this->allow, $path);
        $disallow = self::longestMatch($this->disallow, $path);
        return $disallow === -1 || $allow >= $disallow;
    }

    /** @param string[] $patterns */
    private static function longestMatch(array $patterns, string $path): int
    {
        $longest = -1;
        foreach ($patterns as $pattern) {
            $anchored = str_ends_with($pattern, '$');
            $regex = '#^' . str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#')) . ($anchored ? '$' : '') . '#';
            if (preg_match($regex, $path) === 1) {
                $longest = max($longest, strlen($pattern));
            }
        }
        return $longest;
    }

    /**
     * Rooted path patterns only, one per line: a stray newline in config must
     * not be able to open a directive of its own.
     *
     * @param array<mixed> $paths
     * @return string[]
     */
    private static function clean(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path !== '' && $path[0] === '/' && !preg_match('/[\s#]/', $path)) {
                $out[$path] = $path;
            }
        }
        return array_values($out);
    }
}
