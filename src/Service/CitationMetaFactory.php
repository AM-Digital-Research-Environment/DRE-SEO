<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationMetaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationMeta
    {
        return new CitationMeta(
            $container->get(CitationKindMap::class),
            $container->get(CitationData::class),
        );
    }
}
