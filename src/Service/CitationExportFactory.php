<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationExportFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationExport
    {
        $config = $container->get('Config')['dre_seo']['citation'] ?? [];
        $style = (string) ($config['default_style'] ?? 'chicago');

        return new CitationExport(
            $container->get(CitationFormatter::class),
            in_array($style, CitationFormatter::STYLES, true) ? $style : 'chicago',
        );
    }
}
