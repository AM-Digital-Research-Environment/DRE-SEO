<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationKind;
use DRESeo\Service\CitationKindMap;
use Omeka\Api\Representation\ItemRepresentation;
use PHPUnit\Framework\TestCase;

/**
 * The citation kinds and the template-to-kind map, checked against the
 * shipped configuration and AMIRA's template list (tests/Fixture).
 */
final class CitationKindTest extends TestCase
{
    public function testEveryConfiguredTemplateKindResolvesToAKnownKind(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];

        $this->assertTrue(
            CitationKind::tryFrom($citation['default_kind']) !== null,
            'default_kind must be a known kind'
        );
        foreach ($citation['template_kinds'] as $templateId => $kind) {
            $this->assertTrue(
                CitationKind::tryFrom($kind) !== null,
                "template {$templateId} maps to unknown kind '{$kind}'"
            );
        }
    }

    public function testTheEntityTemplatesAreNotCitableWorks(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $kinds = $config['dre_seo']['citation']['template_kinds'];
        $templates = require __DIR__ . '/../Fixture/amira-templates.php';

        foreach ($templates['entities'] as $templateId => $label) {
            $kind = CitationKind::from($kinds[$templateId]);
            $this->assertTrue($kind->isAuthorityRecord(), "template {$templateId} ({$label}) must be an authority record");
        }
        // The publication templates and the research-item template must be citable.
        foreach ($templates['publications'] + $templates['works'] as $templateId => $label) {
            $kind = CitationKind::tryFrom($kinds[$templateId] ?? '');
            $this->assertTrue($kind !== null, "template {$templateId} ({$label}) has no citation kind");
            $this->assertTrue(!$kind->isAuthorityRecord(), "template {$templateId} ({$label}) must be citable");
        }
    }

    public function testEveryTemplateTheSiteDefinesHasACitationKind(): void
    {
        // The Authority Resource template (6) and the Journal template (23) were
        // both absent from this map, so they fell through to default_kind 'item'
        // and a subject heading was offered as a citable work; the publication
        // templates 24-32 then went live unmapped as well. A template the map
        // does not name is the failure mode, so name them all.
        $config = require __DIR__ . '/../../config/module.config.php';
        $kinds = $config['dre_seo']['citation']['template_kinds'];
        $templates = require __DIR__ . '/../Fixture/amira-templates.php';

        foreach ($templates['entities'] + $templates['publications'] + $templates['works'] as $templateId => $label) {
            $this->assertTrue(
                isset($kinds[$templateId]),
                "template {$templateId} ({$label}) is unmapped and would be cited as a generic work"
            );
        }
    }

    public function testExportTypeTablesCoverEveryKind(): void
    {
        foreach (CitationKind::cases() as $kind) {
            $this->assertTrue($kind->cslType() !== '', "{$kind->value} has no CSL type");
            $this->assertTrue($kind->bibtexType() !== '', "{$kind->value} has no BibTeX type");
            $this->assertTrue($kind->risType() !== '', "{$kind->value} has no RIS type");
        }
    }

    public function testThePublicationKindsCarryTheirExpectedExportTypes(): void
    {
        $this->assertSame('article-journal', CitationKind::Article->cslType());
        $this->assertSame('article', CitationKind::Article->bibtexType());
        $this->assertSame('JOUR', CitationKind::Article->risType());

        $this->assertSame('paper-conference', CitationKind::Conference->cslType());
        $this->assertSame('inproceedings', CitationKind::Conference->bibtexType());
        $this->assertSame('CONF', CitationKind::Conference->risType());

        $this->assertSame('dataset', CitationKind::Dataset->cslType());
        $this->assertSame('DATA', CitationKind::Dataset->risType());

        $this->assertSame('broadcast', CitationKind::Podcast->cslType());
        $this->assertSame('SOUND', CitationKind::Podcast->risType());

        $this->assertSame('motion_picture', CitationKind::Video->cslType());
        $this->assertSame('VIDEO', CitationKind::Video->risType());

        // The unmapped fallback stays a generic document.
        $this->assertSame('document', CitationKind::Item->cslType());
        $this->assertSame('GEN', CitationKind::Item->risType());
    }

    public function testPodcastsAndVideosReadTheirOwnCreatorRoles(): void
    {
        $this->assertSame(['marcrel:hst', 'marcrel:spk'], CitationKind::Podcast->creatorTerms());
        $this->assertSame(['marcrel:spk'], CitationKind::Video->creatorTerms());
        $this->assertSame([], CitationKind::Article->creatorTerms());
    }

    public function testTheKindMapDispatchesOnTheResourceTemplateNotTheClass(): void
    {
        $map = new CitationKindMap([11 => 'article', 21 => 'podcast'], 'item');

        $this->assertSame(CitationKind::Article, $map->forTemplateId(11));
        $this->assertSame(CitationKind::Podcast, $map->forTemplateId(21));
        // Unmapped, null and unknown all fall back to the default.
        $this->assertSame(CitationKind::Item, $map->forTemplateId(99));
        $this->assertSame(CitationKind::Item, $map->forTemplateId(null));

        $item = new ItemRepresentation('An article', 11);
        $this->assertSame(CitationKind::Article, $map->forResource($item));
        $this->assertSame(11, CitationKindMap::templateId($item));
    }

    public function testAConfigTypoResolvesToTheDefaultRatherThanAKindNothingHandles(): void
    {
        $map = new CitationKindMap([11 => 'artcile'], 'item');
        $this->assertSame(CitationKind::Item, $map->forTemplateId(11));
    }

    public function testOrganisationRecordsAreRecognisedSoTheirNamesAreNeverSplit(): void
    {
        $map = new CitationKindMap([2 => 'organization', 4 => 'person'], 'item');

        $this->assertTrue($map->isOrganization(new ItemRepresentation('Africa Multiple', 2)));
        $this->assertTrue(!$map->isOrganization(new ItemRepresentation('Ada Lovelace', 4)));
        $this->assertTrue(!$map->isOrganization(null));
    }
}
