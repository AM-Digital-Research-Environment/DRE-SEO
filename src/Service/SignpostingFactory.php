<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SignpostingFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Signposting
    {
        return new Signposting(
            $container->get(StructuredData::class),
            $container->get(LinkedDataIdentity::class),
        );
    }
}
