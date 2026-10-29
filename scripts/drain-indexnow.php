<?php
declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- a command-line entry point.

// Cron entry point, every five minutes, as the user PHP-FPM runs as:
//
//   */5 * * * * php /path/to/omeka-s/modules/DRESeo/scripts/drain-indexnow.php
//
// Sends the URLs waiting in the IndexNow outbox, one batch per run. With
// --retry-failed it first re-queues the URLs IndexNow refused or that ran out
// of retries: run it once by hand after fixing the cause (the key, the host).
// OMEKA_PATH points at the installation when the module is not in its
// modules/ directory.
//
// The file ships inside modules/, which a PHP-FPM location block may execute on
// request. It is a command, never a page: refuse anything but the CLI.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$omeka = getenv('OMEKA_PATH') ?: dirname(__DIR__, 3);
$omeka = realpath($omeka) ?: $omeka;
if (!is_readable($omeka . '/bootstrap.php')) {
    fwrite(STDERR, "Set OMEKA_PATH to the Omeka installation.\n");
    exit(2);
}
require $omeka . '/bootstrap.php';
$application = \Omeka\Mvc\Application::init(require $omeka . '/application/config/application.config.php');
$services = $application->getServiceManager();
// An inactive or uninstalled module registers no services, and its classes
// are not autoloaded: nothing to drain, and no reason to fail the cron.
if (!$services->has(\DRESeo\Service\PingQueue::class)) {
    exit(0);
}
$repository = new \DRESeo\Service\PingRepository($services->get('Omeka\Connection'));
if (in_array('--retry-failed', (array) ($_SERVER['argv'] ?? []), true)) {
    $repository->retryFailed();
}
// Dispatching records a job row even when there is nothing to send; at one
// run every five minutes that is ~290 empty jobs a day in Admin → Jobs.
if (!$services->get(\DRESeo\Service\PingQueue::class)->isEnabled() || !$repository->hasDue()) {
    exit(0);
}
$services->get('Omeka\Job\Dispatcher')->dispatch(
    \DRESeo\Job\PingSearchEngines::class,
    null,
    $services->get('Omeka\Job\DispatchStrategy\Synchronous')
);
