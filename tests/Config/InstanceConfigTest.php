<?php
declare(strict_types=1);

namespace DRESeo\Test\Config;

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
            ['sitemap', 'breadcrumb', 'structured_data', 'citation'],
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
