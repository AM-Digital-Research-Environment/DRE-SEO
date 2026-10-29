<?php
declare(strict_types=1);

/**
 * DRE SEO module configuration.
 *
 * Wires the public sitemap/robots/IndexNow endpoints (top-level routes that
 * fall through to Omeka), the admin SEO dashboard + static-page table, and the
 * head metadata / structured-data / sitemap services. The instance-specific
 * `dre_seo` block — what AMIRA's template ids, item sets and pages mean — is
 * config/instance.config.php.
 */

namespace DRESeo;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

// Instance data (the dre_seo block) lives in its own file. The two have
// disjoint top-level keys (tests/Config/InstanceConfigTest holds them so), so
// a plain union is enough — and, unlike array_merge_recursive, it holds no
// surprises.
$instance = include __DIR__ . '/instance.config.php';

return $instance + [
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
            Service\DefaultSite::class      => Service\DefaultSiteFactory::class,
            Service\ResourceSummary::class  => Service\ResourceSummaryFactory::class,
            Service\Signposting::class      => Service\SignpostingFactory::class,
            // The DRE Linked Data module's resource URIs, when it is installed.
            Service\LinkedDataIdentity::class => Service\LinkedDataIdentityFactory::class,
            // The serialisers; the plain-text download renders the default style.
            Service\CitationExport::class    => Service\CitationExportFactory::class,
            Service\ImageDimensions::class   => Service\ImageDimensionsFactory::class,
            // The breadcrumb's landing pages and research projects; the
            // resolver also decides the project item-set redirect. The trail
            // is the one dreBreadcrumb() returns and the BreadcrumbList states.
            Service\LandingPages::class      => Service\LandingPagesFactory::class,
            Service\ProjectResolver::class   => Service\ProjectResolverFactory::class,
            Service\BreadcrumbTrail::class   => Service\BreadcrumbTrailFactory::class,
            // The one reading of the module's settings: "on", text, integer.
            Service\SettingsGate::class      => Service\SettingsGateFactory::class,
            // robots.txt: the fixed exclusions and dre_seo.robots.
            Service\RobotsTxt::class         => Service\RobotsTxtFactory::class,
        ],
        // Dependency-free: plain instantiation. Shared, like every
        // service-manager entry — HeadWriter relies on that: its record of
        // what the head says has to span the action and layout render passes
        // of one request.
        'invokables' => [
            Service\HeadWriter::class        => Service\HeadWriter::class,
            // Stateless formatter: no config, no dependencies.
            Service\CitationFormatter::class => Service\CitationFormatter::class,
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
    // renders; dreBreadcrumb($resource) — the trail the theme shows above a
    // record, which is the one the BreadcrumbList states. The theme guards on
    // each helper's existence, so the module stays optional.
    'view_helpers' => [
        'factories' => [
            'dreCitation'   => Service\ViewHelper\CitationFactory::class,
            'dreBreadcrumb' => Service\ViewHelper\BreadcrumbFactory::class,
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
            // The constraint is Service\IndexNowKey::PATTERN, spelled out
            // (WiringTest holds the two equal).
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
];
