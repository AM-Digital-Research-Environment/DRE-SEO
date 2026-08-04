<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Service/CitationExport.php';
require_once __DIR__ . '/../src/Service/CitationFormatter.php';

test('production template mappings include synced YouTube videos', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    assertSameValue('VideoObject', $config['dre_seo']['structured_data']['template_types'][22] ?? null);
    assertSameValue('video', $config['dre_seo']['citation']['template_kinds'][22] ?? null);
});

test('the citation stack is wired end to end', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';

    // The theme calls dreCitation(); without this registration it silently
    // degrades to its own baseline and no citation ever appears.
    assertTrue(
        isset($config['view_helpers']['factories']['dreCitation']),
        'the dreCitation view helper must be registered'
    );
    assertTrue(
        isset($config['controllers']['factories'][DRESeo\Controller\CitationController::class]),
        'the citation controller must be registered'
    );

    $route = $config['router']['routes']['dre-seo-cite'] ?? null;
    assertTrue($route !== null, '/cite must be routed');
    assertSameValue('/cite/:id/:format', $route['options']['route']);
    assertSameValue('\d+', $route['options']['constraints']['id']);

    // The download formats offered must all be serialisable.
    $citation = $config['dre_seo']['citation'];
    foreach ($citation['formats'] as $format) {
        assertTrue(
            isset(DRESeo\Service\CitationExport::FORMATS[$format]),
            "no serialiser for offered format '{$format}'"
        );
    }
    // The default style must be one the formatter actually knows.
    assertTrue(
        in_array($citation['default_style'], DRESeo\Service\CitationFormatter::STYLES, true),
        'default_style must be a supported style'
    );
    foreach (array_keys($citation['styles']) as $style) {
        assertTrue(
            in_array($style, DRESeo\Service\CitationFormatter::STYLES, true),
            "offered style '{$style}' is not supported"
        );
    }
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
