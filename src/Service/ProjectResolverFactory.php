<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ProjectResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ProjectResolver
    {
        return new ProjectResolver(
            // Read as the current user, so a private item never answers.
            static fn (string $dreId): array => $container->get('Omeka\ApiManager')->search('items', [
                'property' => [['property' => 'dre:id', 'type' => 'eq', 'text' => $dreId]],
                'is_public' => 1,
                'limit' => 10,
            ])->getContent(),
            static fn (int $siteId): mixed => $container->get('Omeka\Settings\Site')
                ->get(ProjectResolver::REDIRECT_SETTING, null, $siteId),
        );
    }
}
