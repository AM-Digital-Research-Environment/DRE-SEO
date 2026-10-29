<?php
declare(strict_types=1);

namespace DRESeo\Test\Config;

use DRESeo\Service\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * config/module.config.php is the wiring and config/instance.config.php the
 * AMIRA data; module.config.php returns `$instance + [wiring]`. A plain union
 * silently drops a key both files define, so the two must stay disjoint, and
 * the union must still name a class for every service, controller and helper
 * it wires.
 */
final class InstanceConfigTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../config/';

    /** @return array<string,mixed> */
    private static function load(string $file): array
    {
        return require self::CONFIG . $file;
    }

    public function testTheInstanceFileHoldsTheDreSeoBlockAndNothingElse(): void
    {
        $instance = self::load('instance.config.php');

        $this->assertSame(['dre_seo'], array_keys($instance));
        $this->assertSame(
            ['sitemap', 'robots', 'breadcrumb', 'structured_data', 'citation'],
            array_keys($instance['dre_seo'])
        );
    }

    public function testTheWiringAndTheInstanceDataAreDisjointAndBothReachTheMergedConfig(): void
    {
        $instance = self::load('instance.config.php');
        $merged = self::load('module.config.php');
        $wiring = array_diff_key($merged, $instance);

        $this->assertSame([], array_intersect(array_keys($wiring), array_keys($instance)));
        $this->assertSame($instance['dre_seo'], $merged['dre_seo'], 'the instance block arrives whole');
        $this->assertSame(
            ['service_manager', 'controllers', 'view_helpers', 'form_elements', 'router', 'view_manager', 'navigation'],
            array_keys($wiring)
        );
        // The wiring file itself defines no dre_seo key a union would drop.
        $this->assertStringNotContainsString("'dre_seo' =>", (string) file_get_contents(self::CONFIG . 'module.config.php'));
    }

    /**
     * The crawl rules block the query variants of DRE Search's results page
     * and of Omeka's browse routes, and nothing meant to be crawled: not the
     * landing pages, not clean pagination, not a record paging through its
     * connection lists, not DRE Search's API the results page renders from,
     * not any URL shape the sitemaps list.
     */
    public function testRobotsRulesBlockQueryVariantsButNoPageMeantToBeCrawled(): void
    {
        $rules = self::load('instance.config.php')['dre_seo']['robots'];
        $robots = new RobotsTxt($rules['disallow'], $rules['allow']);

        $blocked = [
            '/s/amira/dre-search?q=ramadan',
            '/s/amira/dre-search?q=x&profile=research_items&f.subject_ss=Islam&date.from=1990',
            '/s/amira/item?property%5B0%5D%5Bproperty%5D=3&property%5B0%5D%5Btext%5D=Islam',
            '/s/amira/item?fulltext_search=gender',
            '/s/amira/item?sort_by=title&page=2',
            '/s/amira/item-set?sort_by=created',
            '/s/amira/item-set/29918?sort_by=title',
            '/s/amira/item-set/29918?property%5B0%5D%5Bproperty%5D=3',
            '/s/amira/media?resource_class_id=36',
            '/admin/dre-seo',
            '/login',
        ];
        foreach ($blocked as $path) {
            $this->assertFalse($robots->allows($path), $path);
        }

        $crawlable = [
            // Landing pages and clean pagination.
            '/s/amira/dre-search',
            '/s/amira/item',
            '/s/amira/item?page=3',
            '/s/amira/item-set',
            '/s/amira/item-set?page=2',
            '/s/amira/item-set/29918',
            '/s/amira/item-set/29918?page=2',
            '/s/amira/media?page=2',
            // A record paging through its connection lists stays indexable.
            '/s/amira/item/42?lr_property=items:12&lr_page=3',
            // What DRE Search's pages and blocks fetch to render.
            '/dre-search/api/search?q=ramadan&profile=research_items',
            '/dre-search/api/facet?field=subject_ss',
            '/modules/DRESearch/asset/dist/dre-search.js',
            // Every URL shape the sitemaps list, and the sitemaps themselves.
            '/s/amira/item/41980',
            '/s/amira/media/5',
            '/s/amira/page/home',
            '/s/amira/page/research-projects',
            '/sitemap.xml',
            '/sitemap-pages.xml',
            '/sitemap-item-sets.xml',
            '/sitemap-items-1.xml',
            '/files/large/abc.jpg',
            '/cite/41980/ris',
        ];
        foreach ($crawlable as $path) {
            $this->assertTrue($robots->allows($path), $path);
        }
    }

    /** Every class the wiring names is a file of src/ (the real container is tests/Integration/WiringTest's). */
    public function testTheWiringStillResolvesToTheModulesClasses(): void
    {
        $merged = self::load('module.config.php');
        $classes = [];
        foreach (['factories', 'invokables'] as $kind) {
            foreach ($merged['service_manager'][$kind] ?? [] as $name => $class) {
                $classes[] = $name;
                $classes[] = $class;
            }
        }
        foreach ($merged['controllers']['factories'] as $name => $factory) {
            $classes[] = $name;
            $classes[] = $factory;
        }
        foreach ($merged['view_helpers']['factories'] as $factory) {
            $classes[] = $factory;
        }
        foreach ($merged['form_elements']['invokables'] as $class) {
            $classes[] = $class;
        }

        $this->assertNotEmpty($classes);
        foreach (array_unique($classes) as $class) {
            $this->assertStringStartsWith('DRESeo\\', $class);
            $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen('DRESeo\\'))) . '.php';
            $this->assertFileExists($file, $class);
        }
    }
}
