<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class HeadMetadataFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): HeadMetadata
    {
        return new HeadMetadata(
            $container->get(SettingsGate::class),
            $container->get(HeadWriter::class),
            $container->get(StructuredData::class),
            $container->get(CitationMeta::class),
            $container->get(CitationKindMap::class),
            $container->get(ResourceSummary::class),
            $container->get(Signposting::class),
            $container->get(LinkedDataIdentity::class),
            $container->get(ImageDimensions::class),
        );
    }
}
