<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Sitemap\SitemapRepository;
use DRESeo\Service\Sitemap\UrlsetWriter;
use DRESeo\Service\Sitemap\XmlCache;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Reads only Omeka's own services and config, so uninstall can build the
 * generator on a deactivated module to remove its cache.
 */
final class SitemapGeneratorFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SitemapGenerator
    {
        $config = $container->get('Config');
        $sitemapConfig = $config['dre_seo']['sitemap'] ?? [];

        $basePath = $config['file_store']['local']['base_path']
            ?? (defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : sys_get_temp_dir());
        $cacheDir = rtrim((string) $basePath, '/\\') . '/dre-seo-cache';
        $logger = $container->get('Omeka\Logger');

        return new SitemapGenerator(
            new SitemapRepository($container->get('Omeka\Connection'), $logger),
            new UrlsetWriter(),
            new XmlCache($cacheDir, $logger),
            is_array($sitemapConfig) ? $sitemapConfig : [],
        );
    }
}
