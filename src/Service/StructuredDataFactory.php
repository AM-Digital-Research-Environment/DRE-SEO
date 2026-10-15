<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class StructuredDataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): StructuredData
    {
        $config = $container->get('Config')['dre_seo']['structured_data'] ?? [];
        return new StructuredData(
            $config['template_types'] ?? [],
            $config['default_type'] ?? 'CreativeWork',
            $container->get(CitationKindMap::class),
            $container->get(LinkedDataIdentity::class),
            [
                'access_free' => (array) ($config['access_free'] ?? []),
                // Resolved on first use, once the request's locale is set.
                'access_statement' => static function () use ($container): string {
                    $custom = trim((string) ($container->get('Omeka\Settings')->get('dre_seo_access_statement', '') ?? ''));
                    if ($custom !== '') {
                        return $custom;
                    }
                    return $container->has('MvcTranslator')
                        ? (string) $container->get('MvcTranslator')->translate(StructuredData::ACCESS_STATEMENT)
                        : StructuredData::ACCESS_STATEMENT;
                },
            ],
            $container->get(LandingPages::class),
            $container->get(ProjectResolver::class),
        );
    }
}
