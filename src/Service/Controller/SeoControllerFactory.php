<?php
declare(strict_types=1);

namespace DRESeo\Service\Controller;

use DRESeo\Controller\Admin\SeoController;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\PageSeoStore;
use DRESeo\Service\ProjectResolver;
use DRESeo\Service\SitemapGenerator;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SeoControllerFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SeoController
    {
        return new SeoController(
            $container->get(SitemapGenerator::class),
            $container->get(PageSeoStore::class),
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\Settings'),
            $container->get(DefaultSite::class),
            $container->get(ProjectResolver::class),
        );
    }
}
