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
            $container->get('Omeka\Settings'),
            $container->get('Omeka\Job\Dispatcher'),
            $container->get('Omeka\Logger'),
            null,
            $container->get('Omeka\Connection'),
        );
    }
}
