<?php
declare(strict_types=1);

use DRESeo\Service\CitationKindMap;
use DRESeo\Service\ResourceSummary;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/CitationKindMap.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/Concern/ResourceValueReader.php';
require_once __DIR__ . '/../src/Service/ResourceSummary.php';

function resourceSummary(): ResourceSummary
{
    $config = require __DIR__ . '/../config/module.config.php';
    $citation = $config['dre_seo']['citation'];

    return new ResourceSummary(new CitationKindMap($citation['template_kinds'], $citation['default_kind']));
}

test('a record with a description is described by it', function (): void {
    $item = new ItemRepresentation('A book', 15, 'book', [
        'dcterms:description' => [new ValueRepresentation("An  account\nof things.")],
    ]);

    assertSameValue('An account of things.', resourceSummary()->describe($item, 'AMIRA'));
});

test('a work without a description is described by its type, authors, year and container', function (): void {
    $chapter = new ItemRepresentation('Introduction', 14, 'book chapter', [
        'bibo:authorList' => [
            new ValueRepresentation('', new ItemRepresentation('Fendler, Ute', 4)),
            new ValueRepresentation('', new ItemRepresentation('Kohl, Marie-Anne', 4)),
            new ValueRepresentation('', new ItemRepresentation('Vierke, Clarissa', 4)),
        ],
        'dcterms:date' => [new ValueRepresentation('2024')],
        'dcterms:isPartOf' => [new ValueRepresentation('Of Worlds and Artworks : A Relational View')],
    ]);

    assertSameValue(
        'Book chapter by Ute Fendler, Marie-Anne Kohl et al. (2024), in Of Worlds and Artworks: A Relational View.',
        resourceSummary()->describe($chapter, 'AMIRA')
    );
});

test('a descriptive record says what kind of record it is and where', function (): void {
    $person = new ItemRepresentation('Aas, Norbert', 4, 'Person');

    assertSameValue('Aas, Norbert — person record in AMIRA.', resourceSummary()->describe($person, 'AMIRA'));
});
