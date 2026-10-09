<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Service/CitationExport.php';
require_once __DIR__ . '/../src/Service/CitationFormatter.php';

test('production template mappings include synced YouTube videos', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    assertSameValue('VideoObject', $config['dre_seo']['structured_data']['template_types'][22] ?? null);
    assertSameValue('video', $config['dre_seo']['citation']['template_kinds'][22] ?? null);
});

test('every template has an explicit schema.org type', function (): void {
    // An unnamed template falls back to default_type CreativeWork, so a
    // newspaper article or a master's thesis is typed as nothing in particular
    // — and a subject heading or a journal is claimed to be a work.
    $config = require __DIR__ . '/../config/module.config.php';
    $types = $config['dre_seo']['structured_data']['template_types'];
    $templates = require __DIR__ . '/amira-templates.php';

    assertSameValue('DefinedTerm', $types[6] ?? null, 'the Authority Resource template holds vocabulary terms');
    assertSameValue('Periodical', $types[23] ?? null, 'the Journal template holds venues');
    foreach ($templates['entities'] + $templates['publications'] + $templates['works'] as $templateId => $label) {
        assertTrue(
            isset($types[$templateId]),
            "template {$templateId} ({$label}) has no schema.org type and would be a generic CreativeWork"
        );
    }
});

test('the publication templates 24-32 are typed for what they are', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $types = $config['dre_seo']['structured_data']['template_types'];
    $kinds = $config['dre_seo']['citation']['template_kinds'];

    assertSameValue('NewsArticle', $types[25] ?? null);
    // ERef's "series_editor" covers editing a book series *or* a journal.
    assertSameValue('CreativeWorkSeries', $types[29] ?? null);
    foreach ([30, 31, 32] as $templateId) {
        assertSameValue('Thesis', $types[$templateId] ?? null, "template {$templateId}");
        assertSameValue('thesis', $kinds[$templateId] ?? null, "template {$templateId}");
    }
    // Encyclopedia entries and legal commentaries sit inside an edited volume.
    assertSameValue('chapter', $kinds[26] ?? null);
    assertSameValue('chapter', $kinds[27] ?? null);
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
