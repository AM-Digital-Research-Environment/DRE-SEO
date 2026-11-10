<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use DRESeo\Service\LinkedDataIdentity;
use DRESeo\Service\ResourceSummary;
use DRESeo\Service\Signposting;
use DRESeo\Service\StructuredData;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation as Resource;
use Omeka\Api\Representation\ItemRepresentation as Item;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation as Value;
use PHPUnit\Framework\TestCase;

/**
 * One list each of author, editor and description terms, read by the citation,
 * the meta tags, the JSON-LD, the page summary and the Signposting links, so
 * a record with more than one of them populated is described alike
 * everywhere. Before, a podcast with dcterms:creator and marcrel:hst was
 * credited to its host in the citation and to its creator in the JSON-LD.
 */
final class TermListsTest extends TestCase
{
    private CitationKindMap $kinds;
    private CitationData $citationData;
    private CitationMeta $citationMeta;
    private StructuredData $structuredData;
    private ResourceSummary $summary;

    protected function setUp(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];
        $this->kinds = new CitationKindMap($citation['template_kinds'], $citation['default_kind']);
        $this->citationData = new CitationData($this->kinds);
        $this->citationMeta = new CitationMeta($this->kinds, $this->citationData, $citation['highwire_excluded_templates']);
        $this->structuredData = new StructuredData($config['dre_seo']['structured_data']['template_types'], 'CreativeWork', $this->kinds);
        $this->summary = new ResourceSummary($this->kinds);
    }

    public function testAPodcastWithACreatorAndAHostIsCreditedToItsHostEverywhere(): void
    {
        // Template 21 is a podcast episode.
        $episode = new Item('An episode', 21, 'Audio document', [
            'dcterms:title' => [new Value('An episode')],
            'dcterms:creator' => [new Value(resource: new Resource('Station, Radio', 2, idValue: 201))],
            'marcrel:hst' => [new Value(resource: new Resource('Host, Hanna', 4, idValue: 202))],
        ], 'https://example.test/s/amira/item/200', 200);

        $citationAuthors = array_map(static fn ($creator) => $creator->literal, $this->citationData->build($episode, null)->authors);
        $this->assertSame(['Host, Hanna'], $citationAuthors);

        $dcCreators = array_values(array_map(
            static fn (array $tag) => $tag[1],
            array_filter($this->citationMeta->tags($episode, null), static fn (array $tag) => $tag[0] === 'DC.creator')
        ));
        $this->assertSame($citationAuthors, $dcCreators, 'DC.creator');

        $jsonLd = $this->structuredData->forResource($episode, new SiteRepresentation(), null, null);
        $this->assertSame(['Hanna Host'], array_map(static fn (array $node) => $node['name'], $jsonLd['author']), 'JSON-LD author');

        $this->assertStringContainsString('by Hanna Host', $this->summary->generated($episode, 'AMIRA'), 'page summary');

        $identity = new LinkedDataIdentity(static fn () => static fn (Resource $resource): array => [
            'uri' => 'https://data.example.test/resource/' . $resource->id(),
        ]);
        $signposting = new Signposting(
            new StructuredData([21 => 'PodcastEpisode'], 'CreativeWork', $this->kinds, $identity),
            $identity
        );
        $authors = array_values(array_filter(
            $signposting->links($episode, 'https://example.test/s/amira/item/200', null),
            static fn (array $link) => $link['rel'] === 'author'
        ));
        $this->assertSame([['rel' => 'author', 'href' => 'https://data.example.test/resource/202']], $authors, 'Signposting author');
    }

    public function testEditorsAreTheCitationsEditorsInTheJsonLdAndOtherContributorsStayContributors(): void
    {
        // Template 15 is a book.
        $book = new Item('A book', 15, 'Book', [
            'dcterms:title' => [new Value('A book')],
            'bibo:editorList' => [new Value(resource: new Resource('Editor, Eva', 4, idValue: 301))],
            'dcterms:contributor' => [new Value(resource: new Resource('Helper, Hal', 4, idValue: 302))],
        ]);

        $editors = array_map(static fn ($creator) => $creator->literal, $this->citationData->build($book, null)->editors);
        $this->assertSame(['Editor, Eva'], $editors);

        $jsonLd = $this->structuredData->forResource($book, new SiteRepresentation(), null, null);
        $this->assertSame(['Eva Editor'], array_map(static fn (array $node) => $node['name'], $jsonLd['editor']));
        $this->assertSame(['Hal Helper'], array_map(static fn (array $node) => $node['name'], $jsonLd['contributor']));
    }

    public function testAnAbstractDescribesARecordBeforeItsFreeTextNote(): void
    {
        // An ERef article: bibo:abstract is the abstract, dcterms:description
        // the EPrints note.
        $article = new Item('An article', 11, 'Article', [
            'dcterms:title' => [new Value('An article')],
            'dcterms:description' => [new Value('Includes bibliographical references.')],
            'bibo:abstract' => [new Value('What the article argues.')],
        ]);
        $abstract = 'What the article argues.';

        $this->assertSame($abstract, $this->citationData->build($article, null)->abstract);
        $this->assertSame($abstract, $this->structuredData->forResource($article, new SiteRepresentation(), null, null)['description']);
        $this->assertSame($abstract, $this->summary->own($article));
        $dcDescription = array_values(array_filter($this->citationMeta->tags($article, null), static fn (array $tag) => $tag[0] === 'DC.description'));
        $this->assertSame([['DC.description', $abstract]], $dcDescription);
    }
}
