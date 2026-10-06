<?php
declare(strict_types=1);

/**
 * The AMIRA instance's resource templates, as GET /api/resource_templates lists
 * them (checked 2026-10-06; MongoDB2OmekaS pins the same ids in
 * config/profiles/amira.json).
 *
 * Both template maps in config/module.config.php are keyed by these ids, and a
 * template either map does not name falls back silently to a generic
 * CreativeWork / 'item' — which is how the publication templates 24-32 went
 * live unmapped. When the site gains a template, add it here: the
 * configuration tests then fail until both maps name it.
 *
 * @return array{entities: array<int,string>, publications: array<int,string>, works: array<int,string>}
 */
return [
    // Descriptive records: no citation, no Highwire tags.
    'entities' => [
        2 => 'Organisation',
        3 => 'Location',
        4 => 'Persons',
        5 => 'Projects',
        6 => 'Authority Resource',
        7 => 'Research Sections',
        23 => 'Journal',
    ],
    // The cluster bibliography — every template in item set 29918, on a
    // non-contiguous range that grows with each new upstream ERef/EPub type.
    'publications' => [
        11 => 'Article',
        12 => 'Working paper',
        13 => 'Conference paper',
        14 => 'Book chapter',
        15 => 'Book',
        16 => 'Doctoral thesis',
        17 => 'Journal issue',
        18 => 'Book review',
        19 => 'Online post',
        20 => 'Research data',
        24 => 'Preprint',
        25 => 'Newspaper article',
        26 => 'Legal commentary',
        27 => 'Encyclopedia entry',
        28 => 'Translation',
        29 => 'Series editorship',
        30 => 'Habilitation',
        31 => "Master's thesis",
        32 => "Bachelor's thesis",
    ],
    // The other citable works.
    'works' => [
        10 => 'Research Items',
        21 => 'Podcasts',
        22 => 'YouTube video',
    ],
];
