<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Controller\Admin\SeoController;
use DRESeo\Controller\CitationController;
use DRESeo\Controller\SitemapController;
use DRESeo\Service\CitationData;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use DRESeo\Service\Controller\CitationControllerFactory;
use DRESeo\Service\Controller\SeoControllerFactory;
use DRESeo\Service\Controller\SitemapControllerFactory;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\HeadMetadata;
use DRESeo\Service\ImageDimensions;
use DRESeo\Service\LandingPages;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\Pinger;
use DRESeo\Service\PingQueue;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\ResourceSummary;
use DRESeo\Service\Signposting;
use DRESeo\Service\SitemapGenerator;
use DRESeo\Service\StructuredData;
use DRESeo\View\Helper\Breadcrumb;
use DRESeo\View\Helper\Citation;
use Laminas\View\HelperPluginManager;
use Omeka\Module as OmekaModule;

/**
 * The module's wiring against the real framework: every factory it registers
 * builds through a Laminas service manager, the theme's view helpers resolve
 * through the module's own config, and the listeners attach where Omeka
 * triggers the events.
 */
final class WiringTest extends OmekaTestCase
{
    /** @var list<class-string> the services module.config.php registers a factory for */
    private const SERVICES = [
        StructuredData::class,
        CitationMeta::class,
        CitationKindMap::class,
        CitationData::class,
        CitationFormatter::class,
        CitationExport::class,
        HeadMetadata::class,
        SitemapGenerator::class,
        PageSeoStore::class,
        Pinger::class,
        PingQueue::class,
        DefaultSite::class,
        ResourceSummary::class,
        Signposting::class,
        LinkedDataIdentity::class,
        ImageDimensions::class,
        LandingPages::class,
        ProjectResolver::class,
    ];

    public function testTheSuiteRunsAgainstOmeka421(): void
    {
        $this->assertSame('4.2.1', OmekaModule::VERSION, 'The integration suite must use Omeka S 4.2.1.');
    }

    public function testEveryServiceFactoryBuildsItsService(): void
    {
        foreach (self::SERVICES as $type) {
            $this->assertInstanceOf($type, $this->services->get($type), sprintf('Factory failed for %s.', $type));
        }
    }

    public function testEveryControllerFactoryBuildsItsController(): void
    {
        $this->assertInstanceOf(
            SitemapController::class,
            (new SitemapControllerFactory())($this->services, SitemapController::class),
            'Sitemap controller factory failed.'
        );
        $this->assertInstanceOf(
            SeoController::class,
            (new SeoControllerFactory())($this->services, SeoController::class),
            'Admin controller factory failed.'
        );
        $this->assertInstanceOf(
            CitationController::class,
            (new CitationControllerFactory())($this->services, CitationController::class),
            'Citation controller factory failed.'
        );
    }

    public function testTheThemeViewHelpersResolveThroughTheModuleConfig(): void
    {
        // The theme calls both; without the registration the rail silently
        // falls back to its baseline.
        $viewHelpers = new HelperPluginManager($this->services, $this->config['view_helpers']);

        $this->assertInstanceOf(
            Citation::class,
            $viewHelpers->get('dreCitation'),
            'The dreCitation view helper did not resolve through the module config.'
        );
        $this->assertInstanceOf(
            Breadcrumb::class,
            $viewHelpers->get('dreBreadcrumb'),
            'The dreBreadcrumb view helper did not resolve through the module config.'
        );
    }

    public function testTheListenersAttachWhereOmekaTriggersTheEvents(): void
    {
        $this->assertCount(
            1,
            $this->sharedEvents->getListeners(['Omeka\\Controller\\Site\\Item'], 'view.show.after'),
            'Resource show listener was not attached.'
        );
        $this->assertCount(
            1,
            $this->sharedEvents->getListeners(['Omeka\\Api\\Adapter\\ItemAdapter'], 'api.create.post'),
            'API content-change listener was not attached.'
        );
        foreach (['Omeka\\Api\\Adapter\\ItemSetAdapter', 'Omeka\\Api\\Adapter\\SitePageAdapter'] as $adapterClass) {
            $this->assertCount(
                1,
                $this->sharedEvents->getListeners([$adapterClass], 'api.delete.pre'),
                sprintf('IndexNow delete listener was not attached to %s.', $adapterClass)
            );
        }
    }
}
