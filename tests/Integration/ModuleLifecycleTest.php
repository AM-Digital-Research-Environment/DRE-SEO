<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Test\Integration\Double\FixedSite;
use DRESeo\Test\Integration\Double\InMemorySettings;
use DRESeo\Test\Integration\Double\InMemorySiteSettings;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Install, upgrade and uninstall as Omeka runs them: on a module that may be
 * inactive, whose src/ nothing has registered for autoloading. The suite's
 * Composer autoloader would hide that boundary, so each test puts a loader in
 * front of it that refuses the module's classes, and runs in a process of its
 * own so no earlier test has loaded them already.
 */
final class ModuleLifecycleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallWithoutActiveModuleAutoloading(): void
    {
        $this->assertDefaultsApplied(false);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpgradeWithoutActiveModuleAutoloading(): void
    {
        $this->assertDefaultsApplied(true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallWithoutActiveModuleAutoloadingDropsTheSiteSettings(): void
    {
        $siteSettings = new InMemorySiteSettings();
        $siteSettings->setTargetId(1);
        foreach (['dre_seo_pages', 'dre_seo_landing_pages', 'dre_seo_project_redirect'] as $key) {
            $siteSettings->set($key, 'x');
        }
        $settings = new InMemorySettings(['dre_seo_sitemap_ttl' => '3600', 'default_site' => '1']);
        $api = $this->createStub(Manager::class);
        $api->method('search')->willReturn(new Response([new FixedSite()]));
        $services = new ServiceManager();
        $services->setService('Omeka\\Settings', $settings);
        $services->setService('Omeka\\Settings\\Site', $siteSettings);
        $services->setService('Omeka\\ApiManager', $api);

        $this->withoutModuleAutoloading(static function () use ($services): void {
            (new \DRESeo\Module())->uninstall($services);
        });

        $this->assertNull($settings->get('dre_seo_sitemap_ttl'));
        $this->assertSame('1', $settings->get('default_site'), 'a core setting is not the module’s to drop');
        foreach (['dre_seo_pages', 'dre_seo_landing_pages', 'dre_seo_project_redirect'] as $key) {
            $this->assertNull($siteSettings->get($key), $key);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLifecycleLoaderResolvesAnyModuleClassFromSrc(): void
    {
        // Anything that reaches the blocker means the module's loader missed it.
        $blocker = static function (string $class): void {
            if (str_starts_with($class, 'DRESeo\\') && $class !== 'DRESeo\\Missing') {
                throw new \RuntimeException('Inactive module cannot autoload ' . $class);
            }
        };
        spl_autoload_register($blocker, true, true);
        try {
            $module = new \DRESeo\Module();
            (new \ReflectionMethod($module, 'loadLifecycleDependencies'))->invoke($module);
            // Not only the classes the lifecycle names today: any file under
            // src/, in a subnamespace too, and a trait as well as a class.
            $this->assertTrue(class_exists('DRESeo\\Service\\LandingPages'));
            $this->assertTrue(class_exists('DRESeo\\Service\\Citation\\CitationRecord'));
            $this->assertTrue(trait_exists('DRESeo\\Service\\Concern\\ResourceValueReader'));
            $this->assertFalse(class_exists('DRESeo\\Missing'));
        } finally {
            spl_autoload_unregister($blocker);
        }
    }

    /**
     * Run install or upgrade twice, as a retried upgrade would: every default
     * the module ships is set once, and a value an administrator chose is
     * never overwritten.
     */
    private function assertDefaultsApplied(bool $upgrade): void
    {
        $settings = new InMemorySettings(['dre_seo_sitemap_ttl' => '3600', 'dre_seo_noindex_browse' => '0']);
        $services = new ServiceManager();
        $services->setService('Omeka\\Settings', $settings);

        $this->withoutModuleAutoloading(static function () use ($services, $upgrade): void {
            $module = new \DRESeo\Module();
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                if ($upgrade) {
                    $module->upgrade('0.15.0', '0.16.0', $services);
                } else {
                    $module->install($services);
                }
            }
        });

        $this->assertSame('1', $settings->get('dre_seo_sitemap_enabled'));
        $this->assertSame('1', $settings->get('dre_seo_jsonld_enabled'));
        $this->assertSame('1', $settings->get('dre_seo_citation_meta'));
        $this->assertSame('3600', $settings->get('dre_seo_sitemap_ttl'), 'an administrator’s value stays');
        $this->assertSame('0', $settings->get('dre_seo_noindex_browse'), 'an administrator’s value stays');
    }

    /**
     * Run $lifecycle with the module's own classes unreachable through
     * Composer, as they are on an inactive module. The test doubles stay
     * reachable: they are the suite's, not the module's.
     */
    private function withoutModuleAutoloading(callable $lifecycle): void
    {
        $this->assertFalse(class_exists('DRESeo\\Service\\LandingPages', false), 'loaded before the test began');
        $blocker = static function (string $class): void {
            if (str_starts_with($class, 'DRESeo\\') && !str_starts_with($class, 'DRESeo\\Test\\')) {
                throw new \RuntimeException('Inactive module cannot autoload ' . $class);
            }
        };
        spl_autoload_register($blocker, true, true);
        try {
            $lifecycle();
        } finally {
            spl_autoload_unregister($blocker);
        }
    }
}
