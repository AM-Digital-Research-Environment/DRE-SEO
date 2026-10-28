<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\CitationMeta;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;

/** The Highwire Press and Dublin Core tags Zotero and Google Scholar read. */
final class CitationMetaTest extends TestCase
{
    private static function citationMetaService(): CitationMeta
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];
        $kinds = new CitationKindMap($citation['template_kinds'], $citation['default_kind']);

        return new CitationMeta($kinds, new CitationData($kinds), $citation['highwire_excluded_templates']);
    }

    /**
     * @param array<int,array{0:string,1:string}> $tags
     * @return string[] every content emitted under $name
     */
    private static function tagValues(array $tags, string $name): array
    {
        return array_values(array_map(
            static fn (array $tag): string => $tag[1],
            array_filter($tags, static fn (array $tag): bool => $tag[0] === $name)
        ));
    }

    public function testAThesisTellsZoteroAndScholarWhatItIsAndWhoAwardedIt(): void
    {
        $thesis = new ItemRepresentation('Das Archiv als Heimat', 16, 'Doctoral thesis', [
            'dcterms:title' => [new ValueRepresentation('Das Archiv als Heimat : Die Fantasie Afrika')],
            'bibo:authorList' => [new ValueRepresentation('', new ItemRepresentation('Greven, Katharina', 4))],
            'dcterms:date' => [new ValueRepresentation('2021', null, null, '2021')],
            'marcrel:dgg' => [new ValueRepresentation('Universität Bayreuth')],
        ], null, 41980, 'fabio:DoctoralThesis');

        $tags = self::citationMetaService()->tags($thesis, 'https://example.test/s/amira/item/41980');

        $this->assertSame(['Das Archiv als Heimat: Die Fantasie Afrika'], self::tagValues($tags, 'citation_title'));
        $this->assertSame(['Greven, Katharina'], self::tagValues($tags, 'citation_author'));
        $this->assertSame(['Universität Bayreuth'], self::tagValues($tags, 'citation_dissertation_institution'));
        $this->assertSame(['2021'], self::tagValues($tags, 'citation_publication_date'));
        // The exact Zotero type, once — never alongside the class label.
        $this->assertSame(['thesis'], self::tagValues($tags, 'DC.type'));
    }

    public function testScholarDatesAreSlashSeparatedAndAsPreciseAsTheRecord(): void
    {
        $article = new ItemRepresentation('An article', 11, 'Journal Article', [
            'dcterms:creator' => [new ValueRepresentation('Example, Ada')],
            'dcterms:date' => [new ValueRepresentation('2025-03-20', null, null, '2025-03-20')],
            'dcterms:isPartOf' => [new ValueRepresentation('Pambazuka News')],
            'bibo:isbn13' => [new ValueRepresentation('978-3-96091-668-0')],
        ]);

        $tags = self::citationMetaService()->tags($article, null);

        $this->assertSame(['2025/03/20'], self::tagValues($tags, 'citation_publication_date'));
        $this->assertSame(['Pambazuka News'], self::tagValues($tags, 'citation_journal_title'));
        // An article keeps its class label: citation_journal_title already types it.
        $this->assertSame(['Journal Article'], self::tagValues($tags, 'DC.type'));
    }

    public function testADescriptiveRecordCarriesDublinCoreOnly(): void
    {
        foreach ([4 => 'Person', 6 => 'Authority file', 23 => 'Journal'] as $templateId => $class) {
            $record = new ItemRepresentation('Aas, Norbert', $templateId, $class, [
                'dcterms:title' => [new ValueRepresentation('Aas, Norbert')],
            ]);
            $tags = self::citationMetaService()->tags($record, 'https://example.test/s/amira/item/778');
            $names = array_unique(array_column($tags, 0));

            $this->assertTrue(
                array_filter($names, static fn (string $name): bool => str_starts_with($name, 'citation_')) === [],
                "template {$templateId} must emit no Highwire tag"
            );
            $this->assertSame([$class], self::tagValues($tags, 'DC.type'), "template {$templateId}");
        }
    }

    public function testItemSetAndMediaPagesCarryNoHighwireTags(): void
    {
        // Not an ItemRepresentation: an item set or a media record.
        $itemSet = new AbstractResourceEntityRepresentation('Publications', null, null, [
            'dcterms:title' => [new ValueRepresentation('Publications')],
        ]);
        $tags = self::citationMetaService()->tags($itemSet, 'https://example.test/s/amira/item-set/29918');

        $this->assertSame([], self::tagValues($tags, 'citation_title'));
        $this->assertSame([], self::tagValues($tags, 'citation_public_url'));
        $this->assertSame(['Publications'], self::tagValues($tags, 'DC.title'));
    }

    public function testAPodcastIsNoPaperDublinCoreCreditsItsHostAndNamesItsZoteroType(): void
    {
        $podcast = new ItemRepresentation('Episode', 21, 'Audio document', [
            'dcterms:title' => [new ValueRepresentation('Episode')],
            'marcrel:hst' => [new ValueRepresentation('Akese, Grace Abena')],
            'marcrel:spk' => [new ValueRepresentation('Raghuram, Parvati')],
        ]);

        $tags = self::citationMetaService()->tags($podcast, null);

        $this->assertSame([], self::highwireNames($tags));
        $this->assertSame(['Akese, Grace Abena'], self::tagValues($tags, 'DC.creator'), 'the host, as on the record page');
        $this->assertSame(['podcast'], self::tagValues($tags, 'DC.type'));
    }

    public function testDCIdentifierIsThePersistentIdentifierWhenThereIsOneElseThePage(): void
    {
        $book = new ItemRepresentation('A book', 15, 'Book', [
            'dcterms:title' => [new ValueRepresentation('A book')],
            'dcterms:creator' => [new ValueRepresentation('Example, Ada')],
            'bibo:doi' => [new ValueRepresentation('10.1234/abcd')],
        ], null, 77);

        $page = 'https://example.test/s/amira/item/77';
        $withUri = self::citationMetaService()->tags($book, $page, 'https://example.test/resource/77');
        $withoutUri = self::citationMetaService()->tags($book, $page);

        $this->assertSame(['https://example.test/resource/77'], self::tagValues($withUri, 'DC.identifier'));
        $this->assertSame([$page], self::tagValues($withUri, 'citation_public_url'), 'the landing page stays the page');
        $this->assertSame([$page], self::tagValues($withoutUri, 'DC.identifier'));
        $this->assertSame(['10.1234/abcd'], self::tagValues($withUri, 'citation_doi'));
    }

    /**
     * @param array<int,array{0:string,1:string}> $tags
     * @return string[] the Highwire tag names emitted
     */
    private static function highwireNames(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_column($tags, 0),
            static fn (string $name): bool => str_starts_with($name, 'citation_')
        )));
    }

    public function testAResearchItemWithNoAuthorIsDescribedInDublinCoreOnly(): void
    {
        // /s/amira/item/8698: a research item video, credited to an artist and a
        // curator, with no author — it carried citation_title and a date.
        $video = new ItemRepresentation('Alles was irgendwie nützt', 10, null, [
            'dcterms:title' => [new ValueRepresentation('Alles was irgendwie nützt')],
            'dcterms:created' => [new ValueRepresentation('1905-07-08', null, null, '1905-07-08', 'numeric:timestamp')],
            'marcrel:art' => [new ValueRepresentation('', new ItemRepresentation('Zwier, Pim', 4))],
        ], null, 8698);

        $tags = self::citationMetaService()->tags($video, 'https://example.test/s/amira/item/8698');

        $this->assertSame([], self::highwireNames($tags));
        $this->assertSame(['Alles was irgendwie nützt'], self::tagValues($tags, 'DC.title'));
        $this->assertSame(['1905-07-08'], self::tagValues($tags, 'DC.date'));
    }

    public function testScholarlyKindsNeedAnAuthorAndJournalIssuesAndSeriesAreNoPapers(): void
    {
        $withoutAuthor = new ItemRepresentation('Anonymous report', 12, 'Report', [
            'dcterms:title' => [new ValueRepresentation('Anonymous report')],
        ]);
        $issue = new ItemRepresentation('Issue 3', 17, 'Journal issue', [
            'dcterms:title' => [new ValueRepresentation('Issue 3')],
            'dcterms:creator' => [new ValueRepresentation('Editor, Ed')],
        ]);
        $series = new ItemRepresentation('A series', 29, 'Series', [
            'dcterms:title' => [new ValueRepresentation('A series')],
            'dcterms:creator' => [new ValueRepresentation('Editor, Ed')],
        ]);
        $dataset = new ItemRepresentation('Data', 20, 'Dataset', [
            'dcterms:title' => [new ValueRepresentation('Data')],
            'dcterms:creator' => [new ValueRepresentation('Example, Ada')],
        ]);
        $chapter = new ItemRepresentation('A chapter', 14, 'Book chapter', [
            'dcterms:title' => [new ValueRepresentation('A chapter')],
            'bibo:authorList' => [new ValueRepresentation('Example, Ada')],
        ]);

        foreach (['report' => $withoutAuthor, 'issue' => $issue, 'series' => $series, 'dataset' => $dataset] as $label => $item) {
            $this->assertSame([], self::highwireNames(self::citationMetaService()->tags($item, null)), $label);
        }
        $this->assertSame(['dataset'], self::tagValues(self::citationMetaService()->tags($dataset, null), 'DC.type'));
        $tags = self::citationMetaService()->tags($chapter, null);
        $this->assertSame(['A chapter'], self::tagValues($tags, 'citation_title'));
        $this->assertSame(['Example, Ada'], self::tagValues($tags, 'citation_author'));
    }

    public function testTheLanguageIsABCP47TagForScholarAndDublinCore(): void
    {
        $german = new ItemRepresentation('German', 6, null, ['dcterms:alternative' => [new ValueRepresentation('deu')]]);
        $dholuo = new ItemRepresentation('Dholuo', 6, null, ['dcterms:alternative' => [new ValueRepresentation('dholuo')]]);
        $book = static fn (ItemRepresentation $language): ItemRepresentation => new ItemRepresentation('A book', 15, 'Book', [
            'dcterms:title' => [new ValueRepresentation('A book')],
            'dcterms:creator' => [new ValueRepresentation('Example, Ada')],
            'dcterms:language' => [new ValueRepresentation('', $language)],
        ]);

        $tags = self::citationMetaService()->tags($book($german), null);
        $this->assertSame(['de'], self::tagValues($tags, 'citation_language'));
        $this->assertSame(['de'], self::tagValues($tags, 'DC.language'));
        $tags = self::citationMetaService()->tags($book($dholuo), null);
        $this->assertSame([], self::tagValues($tags, 'citation_language'), 'no code, no tag');
        $this->assertSame(['Dholuo'], self::tagValues($tags, 'DC.language'), 'Dublin Core keeps the name');
    }
}
