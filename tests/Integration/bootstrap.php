<?php
declare(strict_types=1);

/**
 * Bootstrap for the suite that runs against a real Omeka S installation.
 *
 * OMEKA_PATH must point at an Omeka S 4.2.1 tree with its Composer runtime
 * installed: the official release zip, or a checkout after
 * `composer install --no-dev`. The module adds no framework dependencies of
 * its own, so the Laminas, Doctrine and Omeka classes under test are exactly
 * the ones production loads.
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- a bootstrap configures the process.

$moduleRoot = dirname(__DIR__, 2);
$omekaRoot = rtrim((string) getenv('OMEKA_PATH'), "\\/");
$omekaAutoload = $omekaRoot . '/vendor/autoload.php';
$moduleAutoload = $moduleRoot . '/vendor/autoload.php';

if ($omekaRoot === '' || !is_readable($omekaAutoload)) {
    throw new RuntimeException(
        'Set OMEKA_PATH to an installed Omeka S tree before running the integration suite.'
    );
}
if (!is_readable($moduleAutoload)) {
    throw new RuntimeException('Run composer install in the DRE SEO module first.');
}

if (!defined('OMEKA_PATH')) {
    define('OMEKA_PATH', $omekaRoot);
}

// Omeka owns Laminas, Doctrine and PSR at runtime. Its autoloader must
// therefore win the framework boundary; the module vendor contains
// development tools only.
require_once $omekaAutoload;
require_once $moduleAutoload;

// Neither Module class is under a PSR-4 root: Omeka loads them by path.
require_once $omekaRoot . '/application/Module.php';
require_once $moduleRoot . '/Module.php';

if (!class_exists(Omeka\Api\Representation\ItemRepresentation::class)) {
    throw new RuntimeException('OMEKA_PATH did not provide the Omeka S application classes.');
}
if (!class_exists(Laminas\View\Renderer\PhpRenderer::class)) {
    throw new RuntimeException('OMEKA_PATH did not provide the Laminas framework classes.');
}
