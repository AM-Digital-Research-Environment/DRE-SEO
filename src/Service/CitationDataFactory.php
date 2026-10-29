<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationDataFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationData
    {
        $config = $container->get('Config')['dre_seo']['citation'] ?? [];

        return new CitationData(
            $container->get(CitationKindMap::class),
            isset($config['publisher']) ? (string) $config['publisher'] : null,
        );
    }
}
