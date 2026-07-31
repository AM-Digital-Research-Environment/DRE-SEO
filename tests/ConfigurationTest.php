<?php
declare(strict_types=1);

test('production template mappings include synced YouTube videos', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    assertSameValue('VideoObject', $config['dre_seo']['structured_data']['template_types'][22] ?? null);
    assertSameValue('video', $config['dre_seo']['citation']['template_kinds'][22] ?? null);
});

test('the module never bundles framework-owned Laminas or PSR packages', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__ . '/../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    foreach (array_keys($composer['require'] ?? []) as $package) {
        assertTrue(!str_starts_with($package, 'laminas/'), 'Laminas must come from Omeka core');
        assertTrue(!str_starts_with($package, 'psr/'), 'PSR interfaces must come from Omeka core');
    }
});
