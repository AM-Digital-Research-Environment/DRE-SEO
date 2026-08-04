<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationKindMapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationKindMap
    {
        $config = $container->get('Config')['dre_seo']['citation'] ?? [];

        return new CitationKindMap(
            $config['template_kinds'] ?? [],
            $config['default_kind'] ?? 'item',
        );
    }
}
