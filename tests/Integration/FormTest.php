<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Form\ConfigForm;
use DRESeo\Test\Integration\Double\CallbackHelper;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\Form\ConfigProvider as FormConfigProvider;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\FormElementManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Form\Element\Asset;
use Omeka\Form\SiteSettingsForm;

/**
 * The module's configuration form, built through Laminas' form element
 * manager, and the fields it adds to core's site settings form.
 */
final class FormTest extends OmekaTestCase
{
    public function testTheConfigFormValidatesTheIndexNowKey(): void
    {
        $formConfig = $this->config['form_elements'];
        $formConfig['invokables'][Asset::class] = Asset::class;
        $forms = new FormElementManager($this->services, $formConfig);

        $invalidForm = $forms->build(ConfigForm::class);
        $invalidForm->setData(['dre_seo_indexnow_key' => 'not-a-hex-key']);
        $this->assertFalse($invalidForm->isValid(), 'Config form must reject an invalid IndexNow key.');

        $validForm = $forms->build(ConfigForm::class);
        $validForm->setData(['dre_seo_indexnow_key' => 'abcdef1234567890']);
        $this->assertTrue($validForm->isValid(), 'Config form must accept a valid IndexNow key.');
    }

    public function testTheSiteSettingsFieldsJoinCoreFormWithTheirDefaults(): void
    {
        $form = $this->siteSettingsForm();

        $this->assertTrue(
            $form->has('dre_seo_landing_pages') && $form->has('dre_seo_project_redirect'),
            'The site settings fields were not added.'
        );
        $redirect = $form->get('dre_seo_project_redirect');
        $this->assertInstanceOf(Checkbox::class, $redirect);
        $this->assertTrue($redirect->isChecked(), 'The project redirect must default to on.');
        $this->assertStringContainsString(
            '29918 = publications',
            (string) $form->get('dre_seo_landing_pages')->getAttribute('placeholder'),
            'The landing pages placeholder does not show the defaults.'
        );
    }

    public function testTheSiteSettingsFormRefusesAMalformedLandingPageLine(): void
    {
        $form = $this->siteSettingsForm();
        $events = $this->formEvents();
        $events->triggerEvent(new Event(
            'form.add_input_filters',
            $form,
            ['inputFilter' => $form->getInputFilter()]
        ));

        $form->setData(['dre_seo_project_redirect' => '0', 'dre_seo_landing_pages' => "20 = research-projects\nnot a line"]);
        $this->assertFalse($form->isValid(), 'A malformed landing-page line was accepted.');

        $form->setData(['dre_seo_project_redirect' => '0', 'dre_seo_landing_pages' => "20 = research\n29918 =\n"]);
        $this->assertTrue($form->isValid(), 'Valid site settings were refused: ' . json_encode($form->getMessages()));
    }

    public function testARejectedConfigurationIsShownAgainWithTheOtherEdits(): void
    {
        $this->useFormElementManager();
        $controller = self::configController([
            'dre_seo_default_description' => 'A new description',
            'dre_seo_indexnow_key' => 'not-a-hex-key',
        ]);

        $this->assertFalse($this->module->handleConfigForm($controller));
        $this->assertSame('Smoke-test description', $this->settings->get('dre_seo_default_description'), 'nothing is saved');

        // Omeka shows the form again in the same request: the edit survives.
        $html = html_entity_decode($this->module->getConfigForm(self::formRenderer()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('A new description', $html);
        $this->assertStringContainsString('not-a-hex-key', $html);
    }

    public function testABlankCacheLifetimeIsTheDefaultAndZeroTurnsTheCacheOff(): void
    {
        $this->useFormElementManager();

        $this->assertTrue($this->module->handleConfigForm(self::configController(['dre_seo_sitemap_ttl' => ''])));
        $this->assertSame('86400', $this->settings->get('dre_seo_sitemap_ttl'));

        $this->assertTrue($this->module->handleConfigForm(self::configController(['dre_seo_sitemap_ttl' => '0'])));
        $this->assertSame('0', $this->settings->get('dre_seo_sitemap_ttl'));
    }

    private function useFormElementManager(): void
    {
        $formConfig = $this->config['form_elements'];
        $formConfig['invokables'][Asset::class] = Asset::class;
        $this->services->setAllowOverride(true);
        $this->services->setService('FormElementManager', new FormElementManager($this->services, $formConfig));
    }

    /**
     * The module configuration controller, as handleConfigForm() uses it: the
     * POST, and a messenger that keeps the errors.
     *
     * @param array<string,mixed> $post
     */
    private static function configController(array $post): AbstractActionController
    {
        return new class ($post) extends AbstractActionController {
            /** @param array<string,mixed> $post */
            public function __construct(private readonly array $post)
            {
            }

            /**
             * @param string $method
             * @param array<int,mixed> $params
             */
            public function __call($method, $params): object
            {
                $post = $this->post;
                return match ($method) {
                    'params' => new class ($post) {
                        /** @param array<string,mixed> $post */
                        public function __construct(private readonly array $post)
                        {
                        }

                        /** @return array<string,mixed> */
                        public function fromPost(): array
                        {
                            return $this->post;
                        }
                    },
                    default => new class {
                        public function addErrors(mixed $messages): void
                        {
                        }
                    },
                };
            }
        };
    }

    private static function formRenderer(): PhpRenderer
    {
        $view = new PhpRenderer();
        $helpers = $view->getHelperPluginManager();
        $helpers->configure((new FormConfigProvider())->getViewHelperConfig());
        $helpers->setService('translate', new CallbackHelper(static fn (string $text): string => $text));
        return $view;
    }

    private function formEvents(): EventManager
    {
        return new EventManager($this->sharedEvents, [SiteSettingsForm::class]);
    }

    /** Core's site settings form, after the module's form.add_elements listener ran. */
    private function siteSettingsForm(): SiteSettingsForm
    {
        $form = new SiteSettingsForm();
        $this->formEvents()->triggerEvent(new Event('form.add_elements', $form));
        return $form;
    }
}
