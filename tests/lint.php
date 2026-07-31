<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [$root . '/Module.php', $root . '/config', $root . '/src', $root . '/tests', $root . '/view'];
$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $path,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'phtml'], true)) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = false;
foreach ($files as $file) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to lint {$file}\n");
        $failed = true;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $stdout . $stderr);
        $failed = true;
    }
}

if (!$failed) {
    fwrite(STDOUT, sprintf("PHP syntax valid in %d file(s).\n", count($files)));
}
exit($failed ? 1 : 0);
