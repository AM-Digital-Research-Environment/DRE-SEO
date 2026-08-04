<?php
declare(strict_types=1);

use DRESeo\Service\CitationKind;
use DRESeo\Service\CitationKindMap;
use Omeka\Api\Representation\ItemRepresentation;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/CitationKindMap.php';

test('every configured template kind resolves to a known kind', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $citation = $config['dre_seo']['citation'];

    assertTrue(
        CitationKind::tryFrom($citation['default_kind']) !== null,
        'default_kind must be a known kind'
    );
    foreach ($citation['template_kinds'] as $templateId => $kind) {
        assertTrue(
            CitationKind::tryFrom($kind) !== null,
            "template {$templateId} maps to unknown kind '{$kind}'"
        );
    }
});

test('the entity templates are not citable works', function (): void {
    $config = require __DIR__ . '/../config/module.config.php';
    $kinds = $config['dre_seo']['citation']['template_kinds'];

    // 2 organisation, 3 location, 4 persons, 5 projects, 7 research sections.
    foreach ([2, 3, 4, 5, 7] as $templateId) {
        $kind = CitationKind::from($kinds[$templateId]);
        assertTrue($kind->isAuthorityRecord(), "template {$templateId} must be an authority record");
    }
    // The publication templates and the research-item template must be citable.
    foreach ([10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22] as $templateId) {
        $kind = CitationKind::from($kinds[$templateId]);
        assertTrue(!$kind->isAuthorityRecord(), "template {$templateId} must be citable");
    }
});

test('export type tables cover every kind', function (): void {
    foreach (CitationKind::cases() as $kind) {
        assertTrue($kind->cslType() !== '', "{$kind->value} has no CSL type");
        assertTrue($kind->bibtexType() !== '', "{$kind->value} has no BibTeX type");
        assertTrue($kind->risType() !== '', "{$kind->value} has no RIS type");
    }
});

test('the publication kinds carry their expected export types', function (): void {
    assertSameValue('article-journal', CitationKind::Article->cslType());
    assertSameValue('article', CitationKind::Article->bibtexType());
    assertSameValue('JOUR', CitationKind::Article->risType());

    assertSameValue('paper-conference', CitationKind::Conference->cslType());
    assertSameValue('inproceedings', CitationKind::Conference->bibtexType());
    assertSameValue('CONF', CitationKind::Conference->risType());

    assertSameValue('dataset', CitationKind::Dataset->cslType());
    assertSameValue('DATA', CitationKind::Dataset->risType());

    assertSameValue('broadcast', CitationKind::Podcast->cslType());
    assertSameValue('SOUND', CitationKind::Podcast->risType());

    assertSameValue('motion_picture', CitationKind::Video->cslType());
    assertSameValue('VIDEO', CitationKind::Video->risType());

    // The unmapped fallback stays a generic document.
    assertSameValue('document', CitationKind::Item->cslType());
    assertSameValue('GEN', CitationKind::Item->risType());
});

test('podcasts and videos read their own creator roles', function (): void {
    assertSameValue(['marcrel:hst', 'marcrel:spk'], CitationKind::Podcast->creatorTerms());
    assertSameValue(['marcrel:spk'], CitationKind::Video->creatorTerms());
    assertSameValue([], CitationKind::Article->creatorTerms());
});

test('the kind map dispatches on the resource template, not the class', function (): void {
    $map = new CitationKindMap([11 => 'article', 21 => 'podcast'], 'item');

    assertSameValue(CitationKind::Article, $map->forTemplateId(11));
    assertSameValue(CitationKind::Podcast, $map->forTemplateId(21));
    // Unmapped, null and unknown all fall back to the default.
    assertSameValue(CitationKind::Item, $map->forTemplateId(99));
    assertSameValue(CitationKind::Item, $map->forTemplateId(null));

    $item = new ItemRepresentation('An article', 11);
    assertSameValue(CitationKind::Article, $map->forResource($item));
    assertSameValue(11, CitationKindMap::templateId($item));
});

test('a config typo resolves to the default rather than a kind nothing handles', function (): void {
    $map = new CitationKindMap([11 => 'artcile'], 'item');
    assertSameValue(CitationKind::Item, $map->forTemplateId(11));
});

test('organisation records are recognised so their names are never split', function (): void {
    $map = new CitationKindMap([2 => 'organization', 4 => 'person'], 'item');

    assertTrue($map->isOrganization(new ItemRepresentation('Africa Multiple', 2)));
    assertTrue(!$map->isOrganization(new ItemRepresentation('Ada Lovelace', 4)));
    assertTrue(!$map->isOrganization(null));
});
