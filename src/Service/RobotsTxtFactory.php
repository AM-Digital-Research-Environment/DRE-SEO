<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class RobotsTxtFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): RobotsTxt
    {
        $robots = $container->get('Config')['dre_seo']['robots'] ?? [];
        return new RobotsTxt(
            is_array($robots['disallow'] ?? null) ? $robots['disallow'] : [],
            is_array($robots['allow'] ?? null) ? $robots['allow'] : []
        );
    }
}
