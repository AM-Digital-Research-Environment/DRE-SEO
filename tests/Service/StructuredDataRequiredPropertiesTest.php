<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationKindMap;
use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;

/**
 * What each schema.org type the template map produces must carry, for every
 * AMIRA template, so that remapping a template or reshaping the builder cannot
 * quietly drop a property a consumer requires: Google's required properties
 * for the rich results it offers (Dataset: name and description; VideoObject:
 * name, thumbnailUrl and uploadDate), and what the module promises every node
 * (a description, the page as url, the people a work names, its date).
 */
final class StructuredDataRequiredPropertiesTest extends TestCase
{
    /** Every node, whatever its type. */
    private const ALWAYS = ['@context', '@type', 'name', 'description', 'url'];

    /**
     * Per type, on a record that has an author, a date and an image. A type
     * absent from the table needs no more than ALWAYS.
     */
    private const REQUIRED = [
        'CreativeWork'       => ['author', 'datePublished'],
        'ScholarlyArticle'   => ['author', 'datePublished'],
        'NewsArticle'        => ['author', 'datePublished'],
        'BlogPosting'        => ['author', 'datePublished'],
        'Chapter'            => ['author', 'datePublished'],
        'Book'               => ['author', 'datePublished'],
        'Thesis'             => ['author', 'datePublished'],
        'PublicationIssue'   => ['author', 'datePublished'],
        'CreativeWorkSeries' => ['author', 'datePublished'],
        'PodcastEpisode'     => ['author', 'datePublished'],
        // Google reads a Dataset's people from `creator`, never `author`.
        'Dataset'            => ['creator', 'datePublished'],
        'VideoObject'        => ['author', 'thumbnailUrl', 'uploadDate'],
    ];

    /** Properties a type must not carry, because a consumer misreads them. */
    private const FORBIDDEN = [
        'Dataset'     => ['author'],
        'VideoObject' => ['datePublished'],
    ];

    public function testEveryMappedTemplateCarriesWhatItsTypeRequires(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $templateTypes = $config['dre_seo']['structured_data']['template_types'];
        $citation = $config['dre_seo']['citation'];
        $builder = new StructuredData(
            $templateTypes,
            'CreativeWork',
            new CitationKindMap($citation['template_kinds'], $citation['default_kind'])
        );

        $this->assertNotSame([], $templateTypes);
        foreach ($templateTypes as $templateId => $type) {
            $record = new Item('Record of template ' . $templateId, (int) $templateId, null, [
                'dcterms:title' => [new Value('A title')],
                'dcterms:issued' => [new Value('2024-05-06')],
                'dcterms:creator' => [new Value(resource: new Resource('Author, Ada', 4, idValue: 900))],
                'marcrel:hst' => [new Value(resource: new Resource('Host, Hanna', 4, idValue: 901))],
                'marcrel:spk' => [new Value(resource: new Resource('Speaker, Sam', 4, idValue: 902))],
            ], 'https://example.test/s/amira/item/' . $templateId, (int) $templateId);

            $data = $builder->forResource(
                $record,
                new SiteRepresentation(),
                'https://example.test/s/amira/item/' . $templateId,
                'https://example.test/files/large/' . $templateId . '.jpg'
            );

            $this->assertSame($type, $data['@type'], "template {$templateId}");
            foreach ([...self::ALWAYS, ...(self::REQUIRED[$type] ?? [])] as $property) {
                $this->assertArrayHasKey($property, $data, "template {$templateId} ({$type}) lacks {$property}");
                $this->assertNotSame('', $data[$property], "template {$templateId} ({$type}) has an empty {$property}");
                $this->assertNotSame([], $data[$property], "template {$templateId} ({$type}) has an empty {$property}");
            }
            foreach (self::FORBIDDEN[$type] ?? [] as $property) {
                $this->assertArrayNotHasKey($property, $data, "template {$templateId} ({$type}) carries {$property}");
            }
        }
    }

    public function testEveryWorkTypeTheMapProducesIsInTheTable(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $entityTypes = ['Person', 'Place', 'Organization', 'ResearchProject', 'DefinedTerm', 'Periodical', 'BookSeries'];
        foreach (array_unique($config['dre_seo']['structured_data']['template_types']) as $type) {
            $this->assertTrue(
                in_array($type, $entityTypes, true) || isset(self::REQUIRED[$type]),
                "{$type} is mapped but its required properties are not stated here"
            );
        }
    }
}
