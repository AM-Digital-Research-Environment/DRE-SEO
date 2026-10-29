<?php
declare(strict_types=1);

namespace DRESeo\Form;

use DRESeo\Service\LandingPages;
use DRESeo\Service\ProjectResolver;
use Omeka\Form\SiteSettingsForm;
use Omeka\Settings\SiteSettings;

/**
 * The module's group on Sites → {site} → Settings: the project item-set
 * redirect switch and the breadcrumb's landing pages. Core's site settings
 * form stores every element it is given under the element's name, so adding
 * the elements (form.add_elements) and their filters (form.add_input_filters)
 * is all a site setting needs.
 */
final class SiteSettingsElements
{
    /**
     * @param SiteSettings $siteSettings targeted by core at the site being edited
     * @param array<int,string> $landingDefaults item set id => page slug, the
     *   shipped breadcrumb landing pages the textarea shows as its placeholder
     */
    public function __construct(
        private readonly SiteSettings $siteSettings,
        private readonly array $landingDefaults = [],
    ) {
    }

    public function addTo(SiteSettingsForm $form): void
    {
        $groups = (array) ($form->getOption('element_groups') ?? []);
        $groups['dre_seo'] = 'SEO'; // @translate
        $form->setOption('element_groups', $groups);

        $redirect = $this->siteSettings->get(ProjectResolver::REDIRECT_SETTING);
        $form->add([
            'name'    => ProjectResolver::REDIRECT_SETTING,
            'type'    => 'checkbox',
            'options' => [
                'element_group' => 'dre_seo',
                'label' => 'Redirect a project’s item set page to the project', // @translate
                'info'  => 'A research project is both an item and the item set of its collection. When on, the item set’s page answers with a permanent redirect (301) to the project item, which is then the project’s one page in search engines and the sitemap. An item set is a project’s when its “Is Part Of” links to the public project item, or the public project item has the same dre:id.', // @translate
            ],
            'attributes' => [
                'id'    => ProjectResolver::REDIRECT_SETTING,
                // On unless switched off.
                'value' => in_array($redirect, [null, '', '1', 1, true], true) ? '1' : '0',
            ],
        ]);
        $form->add([
            'name'    => LandingPages::SETTING,
            'type'    => 'textarea',
            'options' => [
                'element_group' => 'dre_seo',
                'label' => 'Breadcrumb landing pages', // @translate
                'info'  => 'The page that presents each item set’s records, named in the breadcrumb between the home page and the record: one “item set id = page slug” per line. A line replaces the module’s default for that item set, and “id =” with no slug removes it. A page that does not exist or is private is skipped. Blank: the defaults shown.', // @translate
            ],
            'attributes' => [
                'id'          => LandingPages::SETTING,
                'rows'        => 8,
                'placeholder' => LandingPages::format($this->landingDefaults),
                'value'       => (string) ($this->siteSettings->get(LandingPages::SETTING) ?? ''),
            ],
        ]);
    }

    /** The filters for the elements above: both optional, the landing pages checked line by line. */
    public static function addFilters(object $inputFilter): void
    {
        $inputFilter->add(['name' => ProjectResolver::REDIRECT_SETTING, 'required' => false]);
        $inputFilter->add([
            'name'       => LandingPages::SETTING,
            'required'   => false,
            'filters'    => [['name' => 'StringTrim']],
            'validators' => [[
                'name'    => 'Callback',
                'options' => [
                    'callback' => static fn ($value): bool => LandingPages::invalidLines((string) $value) === [],
                    'messages' => [
                        'callbackValue' => 'Write one “item set id = page slug” per line, for example “29918 = publications”.', // @translate
                    ],
                ],
            ]],
        ]);
    }
}
