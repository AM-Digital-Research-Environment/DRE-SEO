<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

$failures = [];
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $error) {
        $failures[] = $name;
        fwrite(STDERR, "FAIL  {$name}\n      {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("\n%d test(s), %d failure(s)\n", count($tests), count($failures)));
exit($failures === [] ? 0 : 1);
