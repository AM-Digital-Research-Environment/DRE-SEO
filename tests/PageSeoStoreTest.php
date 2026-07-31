<?php
declare(strict_types=1);

use DRESeo\Service\PageSeoStore;
use Omeka\Settings\SiteSettings;

require_once __DIR__ . '/../src/Service/PageSeoStore.php';

test('page SEO overrides are normalized at the storage boundary', function (): void {
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
        11 => ['title' => '', 'image' => 0, 'robots' => 'noindex, nofollow'],
        0 => ['title' => 'invalid page id'],
        'not-an-id' => ['title' => 'invalid page id'],
        12 => 'invalid fields',
    ]);

    assertSameValue([
        10 => [
            'title' => 'A useful title',
            'description' => 'A useful description',
            'image' => 42,
            'robots' => 'noindex, follow',
        ],
    ], $store->all());
});

test('page SEO overrides stay isolated by site and empty updates remove a page', function (): void {
    $settings = new SiteSettings();
    $store = new PageSeoStore($settings);
    $store->setSite(1);
    $store->set(4, ['title' => 'Site one']);

    $store->setSite(2);
    assertSameValue([], $store->all());
    $store->set(4, ['title' => 'Site two']);

    $store->setSite(1);
    assertSameValue(['title' => 'Site one'], $store->get(4));
    $store->set(4, ['title' => '']);
    assertSameValue([], $store->get(4));

    $store->setSite(2);
    assertSameValue(['title' => 'Site two'], $store->get(4));
});
