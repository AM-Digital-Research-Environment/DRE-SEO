<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Reaches DRE Linked Data's `dreLinkedData` view helper, guarded on its
 * existence like the theme's `dreCitation` lookup, so DRE SEO keeps working
 * when that module is not installed.
 */
final class LinkedDataIdentityFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): LinkedDataIdentity
    {
        return new LinkedDataIdentity(static function () use ($container) {
            if (!$container->has('ViewHelperManager')) {
                return null;
            }
            $helpers = $container->get('ViewHelperManager');
            return $helpers->has('dreLinkedData') ? $helpers->get('dreLinkedData') : null;
        });
    }
}
