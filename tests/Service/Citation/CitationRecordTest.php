<?php
declare(strict_types=1);

namespace DRESeo\Test\Service\Citation;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationKind;
use PHPUnit\Framework\TestCase;

/** CitationRecord: page ranges, the citation link and the theme-facing array. */
final class CitationRecordTest extends TestCase
{
    public function testPageRangesJoinCollapseAndDegrade(): void
    {
        $this->assertSame('185-209', CitationRecord::joinPages('185', '209'));
        $this->assertSame('185', CitationRecord::joinPages('185', '185'));
        $this->assertSame('185', CitationRecord::joinPages('185', null));
        $this->assertSame('209', CitationRecord::joinPages(null, '209'));
        $this->assertSame(null, CitationRecord::joinPages(null, null));
    }

    public function testTheCitationLinkPrefersTheDOIOverTheItemPage(): void
    {
        $withDoi = new CitationRecord(
            id: 1,
            kind: CitationKind::Article,
            doi: '10.1234/abcd',
            url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/1'
        );
        $this->assertSame('https://doi.org/10.1234/abcd', $withDoi->link());

        $withoutDoi = new CitationRecord(
            id: 1,
            kind: CitationKind::Article,
            url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/1'
        );
        $this->assertSame('https://data.africamultiple.uni-bayreuth.de/s/amira/item/1', $withoutDoi->link());

        $this->assertSame(null, (new CitationRecord(id: 1, kind: CitationKind::Article))->link());
    }

    public function testTheThemeFacingArrayFormIsCompleteAndReversible(): void
    {
        $record = new CitationRecord(
            id: 42,
            kind: CitationKind::Chapter,
            title: 'A chapter',
            authors: [Creator::parse('Frédérick Madore', false)],
            editors: [Creator::parse('Ada Lovelace', false)],
            issued: IssuedDate::parse('2018-12-07'),
            publisher: 'Brill',
            bookTitle: 'A book',
            pageFirst: '55',
            pageLast: '80',
            issn: '1234-5678',
            isbn: '978-90-04-00000-0',
            doi: '10.1234/abcd',
            url: 'https://example.test/item/42',
            language: 'English',
            abstract: 'An abstract.',
            keywords: ['Islam', 'Benin'],
            accession: 'abz-01-0000'
        );

        // Every key the theme may read must be present.
        $array = $record->toArray();
        foreach (
            [
            'id', 'kind', 'cslType', 'title', 'authors', 'editors', 'issued', 'container',
            'publisher', 'bookTitle', 'volume', 'issue', 'pageFirst', 'pageLast', 'issn',
            'isbn', 'doi', 'url', 'language', 'abstract', 'keywords', 'accession', 'degree',
            ] as $key
        ) {
            $this->assertTrue(array_key_exists($key, $array), "the published contract is missing '{$key}'");
        }
        $this->assertSame('chapter', $array['kind']);
        $this->assertSame('chapter', $array['cslType']);
        $this->assertSame('Madore', $array['authors'][0]['family']);

        $back = CitationRecord::fromArray($array);
        $this->assertSame($record->title, $back->title);
        $this->assertSame($record->kind, $back->kind);
        $this->assertSame($record->bookTitle, $back->bookTitle);
        $this->assertSame($record->issn, $back->issn);
        $this->assertSame($record->isbn, $back->isbn);
        $this->assertSame('55-80', $back->pageRange());
        $this->assertSame(7, $back->issued->day);
        $this->assertSame(['Islam', 'Benin'], $back->keywords);

        $thesis = new CitationRecord(id: 1, kind: CitationKind::Thesis, degree: 'masters');
        $this->assertSame('masters', CitationRecord::fromArray($thesis->toArray())->degree);
    }
}
