<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Form\PageSeoForm;
use DRESeo\Service\TemplateCoverage;
use DRESeo\Test\Integration\Double\CallbackHelper;
use DRESeo\Test\Integration\Double\FixedPage;
use DRESeo\Test\Integration\Double\FixedSite;
use Laminas\Form\ConfigProvider as FormConfigProvider;
use Laminas\Form\FormElementManager;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplateMapResolver;
use Omeka\Form\ConfirmForm;

/**
 * Admin → SEO's two screens, rendered through Laminas' real PhpRenderer and
 * form helpers: a typo in either template is a 500 for an administrator, and
 * nothing else renders them. Omeka's own helpers (assetUrl, pageTitle, url,
 * translate) are stood in for.
 */
final class AdminViewsTest extends OmekaTestCase
{
    private function renderer(): PhpRenderer
    {
        $view = new PhpRenderer();
        $view->setResolver(new TemplateMapResolver([
            'dashboard' => dirname(__DIR__, 2) . '/view/dre-seo/admin/seo/dashboard.phtml',
            'pages' => dirname(__DIR__, 2) . '/view/dre-seo/admin/seo/pages.phtml',
        ]));
        $helpers = $view->getHelperPluginManager();
        $helpers->configure((new FormConfigProvider())->getViewHelperConfig());
        $helpers->setService('translate', new CallbackHelper(static fn (string $text): string => $text));
        $helpers->setService('assetUrl', new CallbackHelper(static fn (string $file, string $module): string => "/modules/{$module}/asset/{$file}"));
        $helpers->setService('url', new CallbackHelper(static fn (?string $route = null): string => '/' . str_replace('/', '-', (string) $route)));
        $helpers->setService('pageTitle', new CallbackHelper(
            static fn (string $title): string => '<h1>' . htmlspecialchars($title) . '</h1>'
        ));
        return $view;
    }

    /** The markup with Laminas' attribute escaping undone, to read attribute values. */
    private static function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,mixed> $overrides */
    private function dashboard(array $overrides = []): string
    {
        $confirmForm = new ConfirmForm();
        $confirmForm->init();
        $confirmForm->setButtonLabel('Regenerate');

        return $this->renderer()->render('dashboard', $overrides + [
            'site' => new FixedSite(),
            'gscConfigured' => false,
            'jsonLdEnabled' => true,
            'citationEnabled' => true,
            'sitemapEnabled' => true,
            'noindexSite' => false,
            'pingEnabled' => true,
            'indexNowKey' => '',
            'indexNowKeyValid' => true,
            'queueCounts' => ['pending' => 3, 'failed' => 1],
            'sitemapUrl' => 'https://example.test/sitemap.xml',
            'robotsUrl' => 'https://example.test/robots.txt',
            'counts' => ['items' => 1200, 'itemSets' => 30, 'pages' => 12],
            'unmappedTemplates' => [],
            'confirmForm' => $confirmForm,
        ]);
    }

    public function testTheDashboardRendersItsStatusSitemapAndRegenerateButton(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('<h1>SEO</h1>', $html);
        $this->assertStringContainsString('no key set', $html);
        $this->assertStringContainsString('3 pending; 1 failed', $html);
        $this->assertStringContainsString('<strong>1,200</strong> public items', $html);
        $this->assertStringContainsString('href="https://example.test/sitemap.xml"', self::decoded($html));
        $this->assertMatchesRegularExpression('~<input[^>]+type="submit"[^>]+value="Regenerate"~', $html);
        // Laminas escapes attribute values in full (`/` as `&#x2F;`).
        $this->assertStringContainsString('action="/admin-dre-seo-regenerate"', self::decoded($html));
        $this->assertStringContainsString('Every resource template in use has a schema.org type and a citation kind.', $html);
    }

    public function testTheDashboardListsTheTemplatesInUseThatTheMapsDoNotName(): void
    {
        $html = $this->dashboard(['unmappedTemplates' => [
            ['id' => 98, 'label' => 'Patent <b>', 'items' => 1500, 'missing' => ['schema.org type', 'citation kind']],
            ['id' => 99, 'label' => '', 'items' => 2, 'missing' => ['citation kind']],
        ]]);

        $this->assertStringContainsString('Template 98 (Patent &lt;b&gt;): 1,500 items; no schema.org type, citation kind', $html);
        $this->assertStringContainsString('Template 99 (no label): 2 items; no citation kind', $html);
        $this->assertStringNotContainsString('Every resource template in use', $html);
    }

    public function testTheDashboardLeavesTheTemplateSectionOutWhenItCannotCheck(): void
    {
        $this->assertStringNotContainsString('Resource templates', $this->dashboard(['unmappedTemplates' => null]));
    }

    public function testTheStaticPageTableRendersARowPerPageWithItsOverrides(): void
    {
        // Built as Omeka builds it: through the element manager, whose CSRF
        // initializer adds the token the view prints.
        $formConfig = $this->config['form_elements'];
        $formConfig['initializers'][] = \Omeka\Form\Initializer\Csrf::class;
        $form = (new FormElementManager($this->services, $formConfig))->get(PageSeoForm::class);
        $this->assertSame(43200, $form->get('csrf')->getCsrfValidator()->getTimeout(), 'Omeka\'s 12-hour token');

        $html = $this->renderer()->render('pages', [
            'site' => new FixedSite(),
            'pages' => [new FixedPage(10, 'Home', 'home'), new FixedPage(11, 'About <us>', 'about')],
            'overrides' => [11 => ['title' => 'About AMIRA', 'robots' => 'noindex, follow', 'jsonld' => false]],
            'form' => $form,
        ]);

        $this->assertStringContainsString('<h1>Static-page SEO</h1>', $html);
        $this->assertStringContainsString('name="csrf"', $html);
        $this->assertStringContainsString('About &lt;us&gt;', $html);
        $attributes = self::decoded($html);
        $this->assertStringContainsString('name="pages[11][title]"', $attributes);
        $this->assertStringContainsString('value="About AMIRA"', $attributes);
        $this->assertMatchesRegularExpression('~value="noindex, follow"\s+selected~', $attributes);
        $this->assertMatchesRegularExpression('~name="pages\[10\]\[jsonld\]">\s*<option value=""\s+selected~', $attributes);
        $this->assertMatchesRegularExpression('~<option value="off"\s+selected~', $attributes);
    }

    public function testTheTemplateCoverageReadsTheTemplatesItemsUse(): void
    {
        $this->connection->executeStatement('ALTER TABLE resource ADD COLUMN resource_template_id INTEGER');
        $this->connection->executeStatement('CREATE TABLE resource_template (id INTEGER PRIMARY KEY, label VARCHAR(255))');
        $this->connection->executeStatement("INSERT INTO resource_template VALUES (11, 'Article'), (98, 'Patent'), (99, 'Map')");
        // Items 1 and 2 use mapped 11 and unmapped 98; the private item 4
        // counts too (it is still described when made public); item set 3 on
        // template 99 is not an item.
        $this->connection->executeStatement('UPDATE resource SET resource_template_id = 11 WHERE id = 1');
        $this->connection->executeStatement('UPDATE resource SET resource_template_id = 98 WHERE id IN (2, 4)');
        $this->connection->executeStatement('UPDATE resource SET resource_template_id = 99 WHERE id = 3');

        $this->assertSame(
            [['id' => 98, 'label' => 'Patent', 'items' => 2, 'missing' => ['schema.org type', 'citation kind']]],
            $this->services->get(TemplateCoverage::class)->unmapped()
        );
    }

    public function testTheTemplateCoverageSaysNothingWhenTheDatabaseCannotAnswer(): void
    {
        // The fixture has no resource_template table.
        $this->assertNull($this->services->get(TemplateCoverage::class)->unmapped());
    }
}
