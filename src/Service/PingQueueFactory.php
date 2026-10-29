<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class PingQueueFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): PingQueue
    {
        return new PingQueue(
            $container->get(SettingsGate::class),
            $container->get('Omeka\Job\Dispatcher'),
            new PingRepository($container->get('Omeka\Connection')),
            $container->get('Omeka\Logger'),
        );
    }
}
