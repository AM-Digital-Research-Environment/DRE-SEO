<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * The module has no bundled vendor/ and Omeka S is not a Composer dependency,
 * so the suite runs against the module alone: Composer's autoloader (dev
 * tools, the DRESeo\ and DRESeo\Test\ namespaces), then the stand-ins for the
 * Omeka, Laminas and Doctrine classes the module type-hints.
 *
 * Every stand-in is guarded by class_exists(), so if the suite is ever run from
 * inside a real Omeka installation the real classes win and nothing is faked.
 * The real-framework checks live in tests/Integration, with their own
 * bootstrap.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Shim/omeka.php';
