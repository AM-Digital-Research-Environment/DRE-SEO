<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SitemapGeneratorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SitemapGenerator
    {
        $config = $container->get('Config');
        $sitemapConfig = $config['dre_seo']['sitemap'] ?? [];

        $basePath = $config['file_store']['local']['base_path']
            ?? (defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : sys_get_temp_dir());
        $cacheDir = rtrim((string) $basePath, '/\\') . '/dre-seo-cache';

        return new SitemapGenerator(
            $container->get('Omeka\Connection'),
            $sitemapConfig,
            $cacheDir,
            $container->get('Omeka\Logger'),
        );
    }
}
