<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\PageSeoStore;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

/** The per-page SEO overrides, normalised and stored per site. */
final class PageSeoStoreTest extends TestCase
{
    public function testPageSEOOverridesAreNormalizedAtTheStorageBoundary(): void
    {
        $settings = new SiteSettings();
        $store = new PageSeoStore($settings);
        $store->setSite(1);
        $store->replacePages([
            '10' => [
                'title' => '  A useful title  ',
                'description' => "  A useful description\n",
                'image' => '42',
                'robots' => 'noindex, follow',
                'unexpected' => 'discard me',
            ],
            11 => ['title' => '', 'image' => 0, 'robots' => 'noindex, nofollow', 'jsonld' => ''],
            13 => ['jsonld' => 'off'],
            0 => ['title' => 'invalid page id'],
            'not-an-id' => ['title' => 'invalid page id'],
            12 => 'invalid fields',
        ], [10, 11, 12, 13, 0]);

        $this->assertSame([
            10 => [
                'title' => 'A useful title',
                'description' => 'A useful description',
                'image' => 42,
                'robots' => 'noindex, follow',
            ],
            13 => ['jsonld' => false],
        ], $store->all());
    }

    public function testPageSEOOverridesStayIsolatedBySiteAndEmptyUpdatesRemoveAPage(): void
    {
        $settings = new SiteSettings();
        $store = new PageSeoStore($settings);
        $store->setSite(1);
        $store->replacePages([4 => ['title' => 'Site one']], [4]);

        $store->setSite(2);
        $this->assertSame([], $store->all());
        $store->replacePages([4 => ['title' => 'Site two']], [4]);

        $store->setSite(1);
        $this->assertSame(['title' => 'Site one'], $store->get(4));
        $store->replacePages([4 => ['title' => '']], [4]);
        $this->assertSame([], $store->get(4));

        $store->setSite(2);
        $this->assertSame(['title' => 'Site two'], $store->get(4));
    }

    public function testAPageTheTableDidNotListKeepsItsOverrides(): void
    {
        // A global editor sees only public pages; saving the table must not
        // erase the overrides of a private page it never showed.
        $store = new PageSeoStore(new SiteSettings());
        $store->setSite(1);
        $store->replacePages([5 => ['robots' => 'noindex, follow'], 6 => ['title' => 'Old']], [5, 6]);

        $store->replacePages([6 => ['title' => 'New']], [6]);

        $this->assertSame(['robots' => 'noindex, follow'], $store->get(5));
        $this->assertSame(['title' => 'New'], $store->get(6));
        $this->assertTrue($store->isNoindex(1, 5));
        $this->assertFalse($store->isNoindex(2, 5), 'another site');
    }
}
