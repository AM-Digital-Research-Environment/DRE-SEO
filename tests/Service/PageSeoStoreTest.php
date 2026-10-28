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
        $store->replaceAll([
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
        ]);

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
        $store->set(4, ['title' => 'Site one']);

        $store->setSite(2);
        $this->assertSame([], $store->all());
        $store->set(4, ['title' => 'Site two']);

        $store->setSite(1);
        $this->assertSame(['title' => 'Site one'], $store->get(4));
        $store->set(4, ['title' => '']);
        $this->assertSame([], $store->get(4));

        $store->setSite(2);
        $this->assertSame(['title' => 'Site two'], $store->get(4));
    }
}
