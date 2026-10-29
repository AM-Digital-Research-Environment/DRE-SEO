<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class DefaultSiteFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): DefaultSite
    {
        return new DefaultSite(
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\Settings'),
        );
    }
}
