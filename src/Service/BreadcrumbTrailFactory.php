<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class BreadcrumbTrailFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): BreadcrumbTrail
    {
        return new BreadcrumbTrail(
            $container->get(LandingPages::class),
            $container->get(ProjectResolver::class),
        );
    }
}
