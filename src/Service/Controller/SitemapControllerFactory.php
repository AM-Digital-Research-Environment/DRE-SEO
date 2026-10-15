<?php
declare(strict_types=1);

namespace DRESeo\Service\Controller;

use DRESeo\Controller\SitemapController;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\SitemapGenerator;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SitemapControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SitemapController
    {
        return new SitemapController(
            $container->get(SitemapGenerator::class),
            $container->get('Omeka\Settings'),
            $container->get(DefaultSite::class),
            $container->get(PageSeoStore::class),
        );
    }
}
