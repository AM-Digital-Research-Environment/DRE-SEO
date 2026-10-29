<?php
declare(strict_types=1);

namespace DRESeo\Test\Config;

use DRESeo\Controller\CitationController;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Contracts of the merged configuration (config/module.config.php over
 * config/instance.config.php) and composer.json: every template
 * AMIRA defines (tests/Fixture/amira-templates.php) is mapped, and the
 * citation stack is wired end to end.
 */
final class ConfigurationTest extends TestCase
{
    public function testProductionTemplateMappingsIncludeSyncedYouTubeVideos(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $this->assertSame('VideoObject', $config['dre_seo']['structured_data']['template_types'][22] ?? null);
        $this->assertSame('video', $config['dre_seo']['citation']['template_kinds'][22] ?? null);
    }

    public function testEveryTemplateHasAnExplicitSchemaOrgType(): void
    {
        // An unnamed template falls back to default_type CreativeWork, so a
        // newspaper article or a master's thesis is typed as nothing in particular
        // — and a subject heading or a journal is claimed to be a work.
        $config = require __DIR__ . '/../../config/module.config.php';
        $types = $config['dre_seo']['structured_data']['template_types'];
        $templates = require __DIR__ . '/../Fixture/amira-templates.php';

        $this->assertSame('DefinedTerm', $types[6] ?? null, 'the Authority Resource template holds vocabulary terms');
        $this->assertSame('Periodical', $types[23] ?? null, 'the Journal template holds venues');
        foreach ($templates['entities'] + $templates['publications'] + $templates['works'] as $templateId => $label) {
            $this->assertTrue(
                isset($types[$templateId]),
                "template {$templateId} ({$label}) has no schema.org type and would be a generic CreativeWork"
            );
        }
    }

    public function testThePublicationTemplates24To32AreTypedForWhatTheyAre(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $types = $config['dre_seo']['structured_data']['template_types'];
        $kinds = $config['dre_seo']['citation']['template_kinds'];

        $this->assertSame('NewsArticle', $types[25] ?? null);
        // ERef's "series_editor" covers editing a book series *or* a journal.
        $this->assertSame('CreativeWorkSeries', $types[29] ?? null);
        foreach ([30, 31, 32] as $templateId) {
            $this->assertSame('Thesis', $types[$templateId] ?? null, "template {$templateId}");
            $this->assertSame('thesis', $kinds[$templateId] ?? null, "template {$templateId}");
        }
        // Encyclopedia entries and legal commentaries sit inside an edited volume.
        $this->assertSame('chapter', $kinds[26] ?? null);
        $this->assertSame('chapter', $kinds[27] ?? null);
    }

    public function testTheCitationStackIsWiredEndToEnd(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';

        // The theme calls dreCitation(); without this registration it silently
        // degrades to its own baseline and no citation ever appears.
        $this->assertTrue(
            isset($config['view_helpers']['factories']['dreCitation']),
            'the dreCitation view helper must be registered'
        );
        // The theme's visible breadcrumb reads dreBreadcrumb(); without it the
        // theme falls back to Home › record while the BreadcrumbList says more.
        $this->assertTrue(
            isset($config['view_helpers']['factories']['dreBreadcrumb']),
            'the dreBreadcrumb view helper must be registered'
        );
        $this->assertTrue(
            isset($config['controllers']['factories'][CitationController::class]),
            'the citation controller must be registered'
        );

        $route = $config['router']['routes']['dre-seo-cite'] ?? null;
        $this->assertTrue($route !== null, '/cite must be routed');
        $this->assertSame('/cite/:id/:format', $route['options']['route']);
        $this->assertSame('\d+', $route['options']['constraints']['id']);

        // The download formats offered must all be serialisable.
        $citation = $config['dre_seo']['citation'];
        foreach ($citation['formats'] as $format) {
            $this->assertTrue(
                isset(CitationExport::FORMATS[$format]),
                "no serialiser for offered format '{$format}'"
            );
        }
        // The default style must be one the formatter actually knows.
        $this->assertTrue(
            in_array($citation['default_style'], CitationFormatter::STYLES, true),
            'default_style must be a supported style'
        );
        foreach (array_keys($citation['styles']) as $style) {
            $this->assertTrue(
                in_array($style, CitationFormatter::STYLES, true),
                "offered style '{$style}' is not supported"
            );
        }
    }

    public function testTheModuleNeverBundlesFrameworkOwnedLaminasOrPSRPackages(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        foreach (array_keys($composer['require'] ?? []) as $package) {
            $this->assertTrue(!str_starts_with($package, 'laminas/'), 'Laminas must come from Omeka core');
            $this->assertTrue(!str_starts_with($package, 'psr/'), 'PSR interfaces must come from Omeka core');
        }
    }
}
