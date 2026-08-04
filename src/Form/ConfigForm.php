<?php
declare(strict_types=1);

namespace DRESeo\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Filter\StringTrim;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use Omeka\Form\Element\Asset;

/**
 * Module configuration form (Modules → DRE SEO → Configure).
 *
 * Rendered inside Omeka's own module-config <form> (which supplies the CSRF
 * token), so this only contributes fields. Every field is optional: with
 * nothing filled in, the module still emits sensible defaults (site title,
 * Open Graph from the site), a sitemap, and robots.txt — it just has no
 * verification token, no custom description and no share image.
 *
 * The settings keys here are the module's whole persistent surface; they are
 * created on configure and dropped on uninstall (see Module::SETTINGS).
 */
class ConfigForm extends Form
{
    public function init(): void
    {
        // ── Search-engine verification ──────────────────────────────────────
        $this->add([
            'name'    => 'dre_seo_gsc_verification',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'Google Search Console verification', // @translate
                'info'  => 'Paste the HTML-tag snippet Google gives you (the whole <meta name="google-site-verification" …> tag) or just the token. It is injected into the <head> of every public page so Google can verify ownership. Then add the site at search.google.com/search-console and submit /sitemap.xml.', // @translate
            ],
            'attributes' => [
                'id'          => 'dre_seo_gsc_verification',
                'placeholder' => '<meta name="google-site-verification" content="…" />',
            ],
        ]);

        $this->add([
            'name'    => 'dre_seo_bing_verification',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'Bing Webmaster verification', // @translate
                'info'  => 'Optional. Paste the <meta name="msvalidate.01" …> tag or its token from Bing Webmaster Tools.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_bing_verification'],
        ]);

        // ── Defaults applied when a page has nothing more specific ──────────
        $this->add([
            'name'    => 'dre_seo_default_description',
            'type'    => Element\Textarea::class,
            'options' => [
                'label' => 'Default meta description', // @translate
                'info'  => 'Used on pages that have no description of their own (the home page, browse and search pages). Resource pages derive their own description from the item metadata. Aim for 150–160 characters.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_default_description', 'rows' => 3],
        ]);

        $this->add([
            'name'    => 'dre_seo_default_share_image',
            'type'    => Asset::class,
            'options' => [
                'label' => 'Default social share image', // @translate
                'info'  => 'The og:image / Twitter card image used when a page (or an item with no media) is shared. A landscape image around 1200×630 works best.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_default_share_image'],
        ]);

        $this->add([
            'name'    => 'dre_seo_twitter_site',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'Twitter / X @handle', // @translate
                'info'  => 'Optional. The site account, e.g. @AfricaMultiple — emitted as twitter:site.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_twitter_site', 'placeholder' => '@AfricaMultiple'],
        ]);

        // ── Indexing policy ─────────────────────────────────────────────────
        $this->add([
            'name'    => 'dre_seo_noindex_site',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Discourage search engines from indexing the whole site', // @translate
                'info'  => 'Staging switch. When on, every page gets robots "noindex, nofollow" and robots.txt disallows everything. Turn OFF in production.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_noindex_site'],
        ]);

        $this->add([
            'name'    => 'dre_seo_noindex_browse',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Noindex filtered / paginated browse pages', // @translate
                'info'  => 'Keeps faceted and paginated browse URLs out of the index (they add little and dilute crawl budget) while still letting crawlers follow links to the resource pages. Recommended.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_noindex_browse'],
        ]);

        // ── Structured data ─────────────────────────────────────────────────
        $this->add([
            'name'    => 'dre_seo_jsonld_enabled',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Emit schema.org JSON-LD', // @translate
                'info'  => 'Adds structured data (Person, Place, Organization, CreativeWork/Dataset, scholarly types …) to resource pages for richer search results and Google Dataset Search. Recommended.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_jsonld_enabled'],
        ]);

        $this->add([
            'name'    => 'dre_seo_citation_meta',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Emit citations (Zotero / Google Scholar / record page)', // @translate
                'info'  => 'Adds Highwire Press (citation_*) and Dublin Core (DC.*) <meta> tags to resource pages so the Zotero Connector, Google Scholar and reference managers capture each item as a proper reference. Also powers the formatted Chicago/APA/MLA citations shown on record pages and the BibTeX/RIS/CSL-JSON downloads at /cite. Recommended.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_citation_meta'],
        ]);

        // ── Sitemap ─────────────────────────────────────────────────────────
        $this->add([
            'name'    => 'dre_seo_sitemap_enabled',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Serve /sitemap.xml and /robots.txt', // @translate
                'info'  => 'Generates a sitemap index plus per-type child sitemaps for all public resources, and a robots.txt that points at it.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_sitemap_enabled'],
        ]);

        $this->add([
            'name'    => 'dre_seo_sitemap_ttl',
            'type'    => Element\Number::class,
            'options' => [
                'label' => 'Sitemap cache lifetime (seconds)', // @translate
                'info'  => 'How long a generated sitemap is cached before it is rebuilt on the next request. Default 86400 (24h). Use the “Regenerate now” button on the SEO admin page to rebuild immediately.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_sitemap_ttl', 'min' => 0, 'step' => 1, 'placeholder' => '86400'],
        ]);

        // ── IndexNow auto-ping ──────────────────────────────────────────────
        $this->add([
            'name'    => 'dre_seo_ping_enabled',
            'type'    => Element\Checkbox::class,
            'options' => [
                'label' => 'Ping IndexNow when content changes', // @translate
                'info'  => 'Notifies Bing/Yandex (and other IndexNow engines) when a public item or page is added or edited, so it is crawled sooner. Concurrent edits are coalesced into one background batch; very large batches are left to the sitemap. Google is not pinged (its ping endpoint was retired — Google uses robots.txt + Search Console instead).', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_ping_enabled'],
        ]);

        $this->add([
            'name'    => 'dre_seo_indexnow_key',
            'type'    => Element\Text::class,
            'options' => [
                'label' => 'IndexNow key', // @translate
                'info'  => 'A 8–128 character hex key you choose. The module serves it at /{key}.txt so IndexNow can verify ownership. Generate one with: openssl rand -hex 16.', // @translate
            ],
            'attributes' => ['id' => 'dre_seo_indexnow_key'],
        ]);

        // Everything is optional — the module degrades to sensible defaults.
        $inputFilter = $this->getInputFilter();
        foreach ([
            'dre_seo_gsc_verification',
            'dre_seo_bing_verification',
            'dre_seo_default_description',
            'dre_seo_default_share_image',
            'dre_seo_twitter_site',
            'dre_seo_noindex_site',
            'dre_seo_noindex_browse',
            'dre_seo_jsonld_enabled',
            'dre_seo_citation_meta',
            'dre_seo_sitemap_enabled',
            'dre_seo_sitemap_ttl',
            'dre_seo_ping_enabled',
        ] as $name) {
            $inputFilter->add(['name' => $name, 'required' => false]);
        }

        $inputFilter->add([
            'name'       => 'dre_seo_indexnow_key',
            'required'   => false,
            'filters'    => [['name' => StringTrim::class]],
            'validators' => [
                ['name' => StringLength::class, 'options' => ['min' => 8, 'max' => 128]],
                ['name' => Regex::class, 'options' => ['pattern' => '/\A[A-Fa-f0-9]+\z/D']],
            ],
        ]);
    }
}
