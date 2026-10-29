<?php
declare(strict_types=1);

namespace DRESeo\Service\ViewHelper;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\SettingsGate;
use DRESeo\View\Helper\Citation;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Citation
    {
        $config = $container->get('Config')['dre_seo']['citation'] ?? [];

        return new Citation(
            $container->get(CitationData::class),
            $container->get(CitationFormatter::class),
            (string) ($config['default_style'] ?? 'chicago'),
            $config['styles'] ?? [],
            $config['formats'] ?? [],
            $container->get(SettingsGate::class)->isOn('dre_seo_citation_meta', true),
            (string) ($config['curated_label'] ?? 'As catalogued'),
            $container->get(LinkedDataIdentity::class),
        );
    }
}
