<?php
declare(strict_types=1);

namespace DRESeo\Service\ViewHelper;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationFormatter;
use DRESeo\View\Helper\Citation;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Citation
    {
        $config = $container->get('Config')['dre_seo']['citation'] ?? [];
        $settings = $container->get('Omeka\Settings');
        $enabled = $settings->get('dre_seo_citation_meta', '1');

        return new Citation(
            $container->get(CitationData::class),
            $container->get(CitationFormatter::class),
            (string) ($config['default_style'] ?? 'chicago'),
            $config['styles'] ?? [],
            $config['formats'] ?? [],
            $enabled === '1' || $enabled === 1 || $enabled === true,
            (string) ($config['curated_label'] ?? 'As catalogued'),
        );
    }
}
