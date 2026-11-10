<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class TemplateCoverageFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TemplateCoverage
    {
        $config = $container->get('Config')['dre_seo'] ?? [];
        return new TemplateCoverage(
            $container->get('Omeka\Connection'),
            (array) ($config['structured_data']['template_types'] ?? []),
            (array) ($config['citation']['template_kinds'] ?? []),
            $container->get('Omeka\Logger'),
        );
    }
}
