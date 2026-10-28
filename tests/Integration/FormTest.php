<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration;

use DRESeo\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\Form\FormElementManager;
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
        $this->assertTrue($form->get('dre_seo_project_redirect')->isChecked(), 'The project redirect must default to on.');
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
