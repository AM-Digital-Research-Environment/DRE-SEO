<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class LandingPagesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): LandingPages
    {
        return new LandingPages(
            (array) ($container->get('Config')['dre_seo']['breadcrumb']['landing_pages'] ?? []),
            static fn (int $siteId): mixed => $container->get('Omeka\Settings\Site')
                ->get(LandingPages::SETTING, null, $siteId),
        );
    }
}
