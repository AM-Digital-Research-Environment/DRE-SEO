<?php
declare(strict_types=1);

namespace DRESeo\Service\Controller;

use DRESeo\Controller\CitationController;
use DRESeo\Service\CitationData;
use DRESeo\Service\CitationExport;
use DRESeo\Service\DefaultSite;
use DRESeo\Service\LinkedDataIdentity;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationControllerFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationController
    {
        return new CitationController(
            $container->get(CitationData::class),
            $container->get(CitationExport::class),
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\Settings'),
            $container->get(DefaultSite::class),
            $container->get(LinkedDataIdentity::class),
        );
    }
}
