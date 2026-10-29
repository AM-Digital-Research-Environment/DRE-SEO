<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\RobotsTxt;
use PHPUnit\Framework\TestCase;

/** The robots.txt body and the precedence allows() applies to it. */
final class RobotsTxtTest extends TestCase
{
    public function testCoreExclusionsAndSitemapWithoutInstanceRules(): void
    {
        self::assertSame(
            "User-agent: *\nDisallow: /admin/\nDisallow: /login\nDisallow: /logout\nDisallow: /maintenance\n\n"
            . "Sitemap: https://example.test/sitemap.xml\n",
            (new RobotsTxt())->render('https://example.test/sitemap.xml')
        );
    }

    public function testAllowLinesPrecedeDisallowLinesAndTheSitemapIsOptional(): void
    {
        $body = (new RobotsTxt(['/s/*/item?'], ['/s/*/item?page=']))->render(null);

        self::assertStringStartsWith("User-agent: *\nAllow: /s/*/item?page=\nDisallow: /admin/", $body);
        self::assertStringContainsString("Disallow: /s/*/item?\n", $body);
        self::assertStringNotContainsString('Sitemap:', $body);
    }

    /** The staging switch closes the site whole: no instance rule, no exception, no sitemap. */
    public function testAClosedSiteIsDisallowedWhole(): void
    {
        self::assertSame(
            "User-agent: *\nDisallow: /\n",
            (new RobotsTxt(['/s/*/item?'], ['/s/*/item?page=']))->render(null, true)
        );
    }

    public function testConfigCannotInjectDirectives(): void
    {
        $body = (new RobotsTxt(["/x\nAllow: /", 'relative', '', 42, '/ok'], ["/y\r\nSitemap: https://evil.example/"]))->render(null);

        self::assertSame(['User-agent: *'], array_values(preg_grep('/^User-agent/', explode("\n", $body)) ?: []));
        self::assertStringNotContainsString('evil.example', $body);
        self::assertStringNotContainsString('relative', $body);
        self::assertStringContainsString("Disallow: /ok\n", $body);
    }

    public function testLongestMatchWinsAndAllowWinsATie(): void
    {
        $robots = new RobotsTxt(['/s/*/item?', '/tie'], ['/s/*/item?page=', '/tie']);

        self::assertFalse($robots->allows('/s/amira/item?sort_by=title'));
        self::assertTrue($robots->allows('/s/amira/item?page=2'));
        self::assertTrue($robots->allows('/s/amira/item?page=2&sort_by=title'), 'the longer Allow still matches');
        self::assertFalse($robots->allows('/s/amira/item?sort_by=title&page=2'));
        self::assertTrue($robots->allows('/s/amira/item/41980'));
        self::assertTrue($robots->allows('/tie'));
        self::assertFalse($robots->allows('/admin/item'));
    }

    public function testEndAnchorIsHonoured(): void
    {
        $robots = new RobotsTxt(['/*.pdf$']);

        self::assertFalse($robots->allows('/files/original/a.pdf'));
        self::assertTrue($robots->allows('/files/original/a.pdf?download=1'));
    }
}
