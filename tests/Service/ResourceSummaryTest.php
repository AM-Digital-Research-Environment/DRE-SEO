<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationKindMap;
use DRESeo\Service\ResourceSummary;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;

/** The meta description written for a record that has none of its own. */
final class ResourceSummaryTest extends TestCase
{
    private static function resourceSummary(): ResourceSummary
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];

        return new ResourceSummary(new CitationKindMap($citation['template_kinds'], $citation['default_kind']));
    }

    public function testARecordWithADescriptionIsDescribedByIt(): void
    {
        $item = new ItemRepresentation('A book', 15, 'book', [
            'dcterms:description' => [new ValueRepresentation("An  account\nof things.")],
        ]);

        $this->assertSame('An account of things.', self::resourceSummary()->describe($item, 'AMIRA'));
    }

    public function testAWorkWithoutADescriptionIsDescribedByItsTypeAuthorsYearAndContainer(): void
    {
        $chapter = new ItemRepresentation('Introduction', 14, 'book chapter', [
            'bibo:authorList' => [
                new ValueRepresentation('', new ItemRepresentation('Fendler, Ute', 4)),
                new ValueRepresentation('', new ItemRepresentation('Kohl, Marie-Anne', 4)),
                new ValueRepresentation('', new ItemRepresentation('Vierke, Clarissa', 4)),
            ],
            'dcterms:date' => [new ValueRepresentation('2024')],
            'dcterms:isPartOf' => [new ValueRepresentation('Of Worlds and Artworks : A Relational View')],
        ]);

        $this->assertSame(
            'Book chapter by Ute Fendler, Marie-Anne Kohl et al. (2024), in Of Worlds and Artworks: A Relational View.',
            self::resourceSummary()->describe($chapter, 'AMIRA')
        );
    }

    public function testADescriptiveRecordSaysWhatKindOfRecordItIsAndWhere(): void
    {
        $person = new ItemRepresentation('Aas, Norbert', 4, 'Person');

        $this->assertSame('Aas, Norbert — person record in AMIRA.', self::resourceSummary()->describe($person, 'AMIRA'));
    }
}
