<?php
declare(strict_types=1);

/**
 * DRE SEO module configuration.
 *
 * Wires the public sitemap/robots/IndexNow endpoints (top-level routes that
 * fall through to Omeka), the admin SEO dashboard + static-page table, the head
 * metadata / structured-data / sitemap services, and the instance-specific
 * `dre_seo` config block (overridable, key by key, via config/local.config.php).
 */

namespace DRESeo;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

return [
    'service_manager' => [
        'factories' => [
            Service\HeadMetadata::class     => Service\HeadMetadataFactory::class,
            Service\StructuredData::class   => Service\StructuredDataFactory::class,
            Service\CitationMeta::class     => Service\CitationMetaFactory::class,
            Service\CitationKindMap::class  => Service\CitationKindMapFactory::class,
            Service\CitationData::class     => Service\CitationDataFactory::class,
            Service\SitemapGenerator::class => Service\SitemapGeneratorFactory::class,
            Service\PageSeoStore::class     => Service\PageSeoStoreFactory::class,
            Service\Pinger::class           => Service\PingerFactory::class,
            Service\PingQueue::class        => Service\PingQueueFactory::class,
        ],
        'invokables' => [
            // Stateless serialisers: no config, no dependencies.
            Service\CitationFormatter::class => Service\CitationFormatter::class,
            Service\CitationExport::class    => Service\CitationExport::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SitemapController::class    => Service\Controller\SitemapControllerFactory::class,
            Controller\CitationController::class   => Service\Controller\CitationControllerFactory::class,
            Controller\Admin\SeoController::class  => Service\Controller\SeoControllerFactory::class,
        ],
    ],

    // dreCitation($item) — the citation view-model the theme's record rail
    // renders. The theme guards on the helper's existence, so the module stays
    // optional.
    'view_helpers' => [
        'factories' => [
            'dreCitation' => Service\ViewHelper\CitationFactory::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class  => Form\ConfigForm::class,
            Form\PageSeoForm::class => Form\PageSeoForm::class,
        ],
    ],

    'router' => [
        'routes' => [
            // ── Public, host-root endpoints. nginx `try_files … /index.php`
            // routes these to Omeka (the .xml/.txt extensions are not served as
            // static files because no such files exist), so no web-server
            // configuration is required.
            'dre-seo-sitemap' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'index'],
                ],
            ],
            'dre-seo-sitemap-pages' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap-pages.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'pages'],
                ],
            ],
            'dre-seo-sitemap-item-sets' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap-item-sets.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'itemSets'],
                ],
            ],
            'dre-seo-sitemap-items' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/sitemap-items-:chunk.xml',
                    'constraints' => ['chunk' => '\d+'],
                    'defaults'    => ['controller' => Controller\SitemapController::class, 'action' => 'items'],
                ],
            ],
            'dre-seo-robots' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/robots.txt',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'robots'],
                ],
            ],
            // Citation downloads at /cite/{id}/{format}. A new first segment,
            // so it cannot collide with the sitemap/robots literals above nor
            // with the IndexNow catch-all below (which only matches *.txt).
            'dre-seo-cite' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/cite/:id/:format',
                    'constraints' => ['id' => '\d+', 'format' => '[a-z]+'],
                    'defaults'    => [
                        'controller' => Controller\CitationController::class,
                        'action'     => 'index',
                    ],
                ],
            ],

            // IndexNow ownership key at /{key}.txt. Constrained to a hex key so
            // it cannot shadow robots.txt; low priority so literals match first.
            'dre-seo-indexnow' => [
                'type'     => Segment::class,
                'priority' => -100,
                'options'  => [
                    'route'       => '/:key.txt',
                    'constraints' => ['key' => '[A-Fa-f0-9]{8,128}'],
                    'defaults'    => ['controller' => Controller\SitemapController::class, 'action' => 'indexNowKey'],
                ],
            ],

            // ── Admin: SEO dashboard + static-page table.
            'admin' => [
                'child_routes' => [
                    'dre-seo' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/dre-seo',
                            'defaults' => [
                                '__NAMESPACE__' => 'DRESeo\Controller\Admin',
                                'controller'    => Controller\Admin\SeoController::class,
                                'action'        => 'dashboard',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes'  => [
                            'pages' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/pages',
                                    'defaults' => ['action' => 'pages'],
                                ],
                            ],
                            'regenerate' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/regenerate',
                                    'defaults' => ['action' => 'regenerate'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    // Sidebar entry under Omeka's "Modules" admin menu.
    'navigation' => [
        'AdminModule' => [
            [
                'label'    => 'SEO', // @translate
                'route'    => 'admin/dre-seo',
                'resource' => Controller\Admin\SeoController::class,
                'class'    => 'o-icon-search',
                'pages'    => [
                    ['route' => 'admin/dre-seo/pages', 'label' => 'Static pages'], // @translate
                    ['route' => 'admin/dre-seo/regenerate', 'visible' => false],
                ],
            ],
        ],
    ],

    // ── Instance configuration (override via config/local.config.php) ───────
    'dre_seo' => [
        'sitemap' => [
            'item_chunk_size' => 50000,
            'priority' => [
                'home'    => '1.0',
                'section' => '0.8',
                'project' => '0.8',
                'item'    => '0.6',
                'page'    => '0.5',
                'browse'  => '0.4',
            ],
            'changefreq' => [
                'home'   => 'daily',
                'item'   => 'monthly',
                'page'   => 'monthly',
                'browse' => 'weekly',
            ],
        ],
        'structured_data' => [
            // schema.org @type per Omeka resource template id (see
            // MongoDB2OmekaS/config/config.py RESOURCE_TEMPLATES + PUBLICATION_TEMPLATES).
            'default_type'   => 'CreativeWork',
            'template_types' => [
                2  => 'Organization',     // organisation (foaf:Organization)
                3  => 'Place',            // location
                4  => 'Person',           // persons
                5  => 'ResearchProject',  // projects
                7  => 'Collection',       // research sections
                10 => 'CreativeWork',     // research items
                11 => 'ScholarlyArticle', // article (fabio:JournalArticle)
                12 => 'CreativeWork',     // working paper
                13 => 'ScholarlyArticle', // conference paper
                14 => 'Chapter',          // book chapter
                15 => 'Book',             // book
                16 => 'Thesis',           // doctoral thesis
                17 => 'PublicationIssue', // journal issue
                18 => 'ScholarlyArticle', // book review (not schema.org Review: scholarly reviews carry no reviewRating, so Google rejects the Review type)
                19 => 'BlogPosting',      // online post
                20 => 'Dataset',          // research data
                21 => 'PodcastEpisode',   // podcasts (fabio:AudioDocument)
                22 => 'VideoObject',      // YouTube video (bibo:AudioVisualDocument)
            ],
        ],
        'citation' => [
            // Highwire Press citation kind per resource template id. Entity
            // kinds (person/place/organization/project/section) emit Dublin Core
            // only; the rest emit kind-specific citation_* tags for Zotero.
            'default_kind'   => 'item',
            'template_kinds' => [
                2  => 'organization', // organisation
                3  => 'place',        // location
                4  => 'person',       // persons
                5  => 'project',      // projects
                6  => 'authority',    // subjects, languages, genres, sponsors, …
                7  => 'section',      // research sections
                10 => 'item',         // research items (generic document)
                11 => 'article',      // journal article
                12 => 'report',       // working paper
                13 => 'conference',   // conference paper
                14 => 'chapter',      // book chapter
                15 => 'book',         // book
                16 => 'thesis',       // doctoral thesis
                17 => 'article',      // journal issue
                18 => 'article',      // book review
                19 => 'post',         // online post
                20 => 'dataset',      // research data
                21 => 'podcast',      // podcast episode
                22 => 'video',        // YouTube video
                23 => 'journal',      // publication venue, not a work
            ],

            // The formatted citations offered in the record rail, in the order
            // the theme shows them. A hand-entered dcterms:bibliographicCitation
            // is prepended by the view helper as the 'curated' style and takes
            // precedence over `default_style`.
            'default_style' => 'chicago',
            'styles'        => [
                'chicago' => 'Chicago',
                'apa'     => 'APA',
                'mla'     => 'MLA',
            ],
            'curated_label' => 'As catalogued',

            // Download serialisations offered at /cite/{id}/{format}.
            'formats' => ['bibtex', 'ris', 'csljson'],
        ],
    ],
];
