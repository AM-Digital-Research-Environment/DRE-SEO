<?php
declare(strict_types=1);

namespace DRESeo\Service\ViewHelper;

use DRESeo\Service\StructuredData;
use DRESeo\View\Helper\Breadcrumb;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class BreadcrumbFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Breadcrumb
    {
        return new Breadcrumb($container->get(StructuredData::class));
    }
}
