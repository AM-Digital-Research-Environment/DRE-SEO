<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DateTimeImmutable;
use DateTimeZone;
use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\CitationKind;
use DRESeo\Service\HttpCache;
use PHPUnit\Framework\TestCase;

/** The BibTeX, RIS, CSL-JSON and plain-text downloads served at /cite. */
final class CitationExportTest extends TestCase
{
    private static function exportFixture(CitationKind $kind = CitationKind::Article, ?string $degree = null): CitationRecord
    {
        return new CitationRecord(
            id: 42,
            kind: $kind,
            title: 'Muslim Politics',
            authors: [Creator::parse('Jean-Louis Triaud', false)],
            editors: [Creator::parse('David Robinson', false)],
            issued: IssuedDate::parse('2018-12-07'),
            container: 'Journal of African History',
            publisher: 'Brill',
            volume: '42',
            issue: '3',
            pageFirst: '185',
            pageLast: '209',
            issn: '1234-5678',
            doi: '10.1234/ab_cd',
            url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/42',
            language: 'English',
            abstract: 'An abstract.',
            keywords: ['Islam', 'Benin'],
            accession: 'abz-01-0000',
            degree: $degree,
        );
    }

    public function testBibTeXCarriesTheEntryTypeCiteKeyAndJournalFields(): void
    {
        $bibtex = (new CitationExport())->serialize(self::exportFixture(), 'bibtex');

        $this->assertTrue(str_starts_with($bibtex, '@article{abz-01-0000,'), 'entry type and cite key');
        $this->assertTrue(str_contains($bibtex, 'author    = {Triaud, Jean-Louis}'), 'inverted author');
        $this->assertTrue(str_contains($bibtex, 'editor    = {Robinson, David}'), 'editor');
        $this->assertTrue(str_contains($bibtex, 'title     = {{Muslim Politics}}'), 'title case is brace-protected');
        $this->assertTrue(str_contains($bibtex, 'journal   = {Journal of African History}'), 'container is the journal');
        $this->assertTrue(str_contains($bibtex, 'year      = {2018}'), 'year');
        $this->assertTrue(str_contains($bibtex, 'pages     = {185--209}'), 'en-dashed page range');
        $this->assertTrue(str_contains($bibtex, 'issn      = {1234-5678}'), 'issn');
        $this->assertTrue(str_contains($bibtex, 'keywords  = {Islam, Benin}'), 'keywords');
        $this->assertTrue(str_ends_with($bibtex, "}\n"), 'the entry is closed');
    }

    public function testBibTeXLeavesDOIAndURLVerbatimButEscapesLaTeXSpecialsElsewhere(): void
    {
        $export = new CitationExport();
        $record = new CitationRecord(
            id: 1,
            kind: CitationKind::Book,
            title: 'Faith & Power_now',
            authors: [Creator::parse('Africa Multiple Cluster of Excellence', true)],
            publisher: 'Brill',
            doi: '10.1234/ab_cd',
            url: 'https://example.test/s/amira/item/1?x=a_b',
        );
        $bibtex = $export->serialize($record, 'bibtex');

        // An underscore must reach biber literally inside doi/url…
        $this->assertTrue(str_contains($bibtex, 'doi       = {10.1234/ab_cd}'), 'the DOI is verbatim');
        $this->assertTrue(str_contains($bibtex, 'url       = {https://example.test/s/amira/item/1?x=a_b}'), 'the URL is verbatim');
        // …but must be escaped in a title, where LaTeX would read it as a subscript.
        $this->assertTrue(str_contains($bibtex, 'Faith \\& Power\\_now'), 'LaTeX specials are escaped in text fields');
        // An institution stays one braced field so BibTeX cannot split it.
        $this->assertTrue(str_contains($bibtex, 'author    = {{Africa Multiple Cluster of Excellence}}'), 'institution braced');
    }

    public function testBibTeXRoutesTheContainerByKind(): void
    {
        $export = new CitationExport();
        // Field names are column-aligned to the widest key in the entry, so match
        // the field rather than a fixed run of spaces.
        $field = static fn (string $bibtex, string $name, string $value): bool
            => (bool) preg_match('/\b' . $name . '\s+= \{' . preg_quote($value, '/') . '\}/', $bibtex);

        $conference = $export->serialize(self::exportFixture(CitationKind::Conference), 'bibtex');
        $this->assertTrue(str_starts_with($conference, '@inproceedings{'), 'conference entry type');
        $this->assertTrue($field($conference, 'booktitle', 'Journal of African History'), 'proceedings in booktitle');

        $thesis = $export->serialize(self::exportFixture(CitationKind::Thesis, 'doctoral'), 'bibtex');
        $this->assertTrue(str_starts_with($thesis, '@phdthesis{'), 'thesis entry type');
        $this->assertTrue($field($thesis, 'school', 'Brill'), 'the awarding institution is the school');
        $this->assertTrue(!preg_match('/^\s*type\s/m', $thesis), 'a dissertation needs no type override');

        // Classic BibTeX has no bachelor's or generic thesis; `type` relabels them.
        $masters = $export->serialize(self::exportFixture(CitationKind::Thesis, 'masters'), 'bibtex');
        $this->assertTrue(str_starts_with($masters, '@mastersthesis{'), "master's thesis entry type");
        $bachelors = $export->serialize(self::exportFixture(CitationKind::Thesis, 'bachelors'), 'bibtex');
        $this->assertTrue(str_starts_with($bachelors, '@mastersthesis{'), "bachelor's thesis entry type");
        $this->assertTrue($field($bachelors, 'type', "Bachelor's thesis"), "a bachelor's thesis is relabelled");
        $habilitation = $export->serialize(self::exportFixture(CitationKind::Thesis), 'bibtex');
        $this->assertTrue(str_starts_with($habilitation, '@phdthesis{'), 'a thesis with no degree stays @phdthesis');
        $this->assertTrue($field($habilitation, 'type', 'Thesis'), 'but is not printed as a PhD');

        $report = $export->serialize(self::exportFixture(CitationKind::Report), 'bibtex');
        $this->assertTrue(str_starts_with($report, '@techreport{'), 'report entry type');
        $this->assertTrue($field($report, 'institution', 'Brill'), 'the issuing body is the institution');

        $this->assertTrue(str_starts_with($export->serialize(self::exportFixture(CitationKind::Dataset), 'bibtex'), '@dataset{'));
    }

    public function testARecordWithNoAccessionIdFallsBackToAnItemCiteKey(): void
    {
        $export = new CitationExport();
        $record = new CitationRecord(id: 42, kind: CitationKind::Item, title: 'A record');

        $this->assertTrue(str_starts_with($export->serialize($record, 'bibtex'), '@misc{item-42,'));
        $this->assertSame('item-42.bib', $export->filename($record, 'bibtex'));
    }

    public function testRISIsCRLFDelimitedTypedAndTerminated(): void
    {
        $ris = (new CitationExport())->serialize(self::exportFixture(), 'ris');

        $this->assertTrue(str_starts_with($ris, "TY  - JOUR\r\n"), 'the type tag opens the record');
        $this->assertTrue(str_contains($ris, "AU  - Triaud, Jean-Louis\r\n"), 'author');
        $this->assertTrue(str_contains($ris, "ED  - Robinson, David\r\n"), 'editor');
        $this->assertTrue(str_contains($ris, "T2  - Journal of African History\r\n"), 'container in T2');
        $this->assertTrue(str_contains($ris, "PY  - 2018\r\n"), 'year');
        $this->assertTrue(str_contains($ris, "DA  - 2018/12/07\r\n"), 'full date, zero-padded');
        $this->assertTrue(str_contains($ris, "SP  - 185\r\n"), 'start page');
        $this->assertTrue(str_contains($ris, "EP  - 209\r\n"), 'end page');
        $this->assertTrue(str_contains($ris, "SN  - 1234-5678\r\n"), 'issn in the standard-number slot');
        $this->assertTrue(str_contains($ris, "DO  - 10.1234/ab_cd\r\n"), 'doi');
        $this->assertTrue(str_contains($ris, "KW  - Islam\r\n") && str_contains($ris, "KW  - Benin\r\n"), 'one KW per keyword');
        $this->assertTrue(str_ends_with($ris, "ER  - \r\n"), 'the end tag closes the record');
    }

    public function testRISCollapsesNewlinesInsideAValue(): void
    {
        $record = new CitationRecord(
            id: 1,
            kind: CitationKind::Item,
            title: 'A record',
            abstract: "First line.\nSecond line."
        );
        $ris = (new CitationExport())->serialize($record, 'ris');

        $this->assertTrue(str_contains($ris, 'AB  - First line. Second line.'), 'a value stays on one line');
    }

    public function testCSLJSONIsAnArrayOfOneTypedItem(): void
    {
        $json = (new CitationExport())->serialize(self::exportFixture(), 'csljson');
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(is_array($decoded) && count($decoded) === 1, 'CSL-JSON is an array of items');
        $item = $decoded[0];
        $this->assertSame('abz-01-0000', $item['id']);
        $this->assertSame('article-journal', $item['type']);
        $this->assertSame('Muslim Politics', $item['title']);
        $this->assertSame('Triaud', $item['author'][0]['family']);
        $this->assertSame('Jean-Louis', $item['author'][0]['given']);
        $this->assertSame('Journal of African History', $item['container-title']);
        $this->assertSame([[2018, 12, 7]], $item['issued']['date-parts']);
        $this->assertSame('185-209', $item['page']);
        $this->assertSame('1234-5678', $item['ISSN']);
        $this->assertSame('10.1234/ab_cd', $item['DOI']);
    }

    public function testCSLJSONKeepsAnInstitutionAsALiteralName(): void
    {
        $record = new CitationRecord(
            id: 1,
            kind: CitationKind::Report,
            title: 'Annual report',
            authors: [Creator::parse('Africa Multiple Cluster of Excellence', true)]
        );
        $decoded = json_decode(
            (string) (new CitationExport())->serialize($record, 'csljson'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            ['literal' => 'Africa Multiple Cluster of Excellence'],
            $decoded[0]['author'][0]
        );
    }

    public function testADateLessRecordOmitsTheIssuedBlockEntirely(): void
    {
        $record = new CitationRecord(id: 1, kind: CitationKind::Item, title: 'A record');
        $decoded = json_decode(
            (string) (new CitationExport())->serialize($record, 'csljson'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertTrue(!array_key_exists('issued', $decoded[0]), 'no date means no date-parts');
        $this->assertTrue(!str_contains((string) (new CitationExport())->serialize($record, 'ris'), 'PY  - '));
    }

    public function testDownloadFilenamesAreSanitisedAndExtensionCorrect(): void
    {
        $export = new CitationExport();
        $record = self::exportFixture();

        $this->assertSame('abz-01-0000.bib', $export->filename($record, 'bibtex'));
        $this->assertSame('abz-01-0000.ris', $export->filename($record, 'ris'));
        $this->assertSame('abz-01-0000.json', $export->filename($record, 'csljson'));

        $awkward = new CitationRecord(id: 7, kind: CitationKind::Item, accession: 'a b/c"d');
        $name = $export->filename($awkward, 'bibtex');
        $this->assertTrue((bool) preg_match('/^[A-Za-z0-9._-]+$/', $name), "unsafe filename: {$name}");
    }

    public function testAnUnknownFormatSerialisesToNothing(): void
    {
        $this->assertSame(null, (new CitationExport())->serialize(self::exportFixture(), 'endnote'));
    }

    public function testAnAuthorityRecordExportsAsAWebPageOfTheDatabase(): void
    {
        $export = new CitationExport();
        $record = new CitationRecord(
            id: 778,
            kind: CitationKind::Person,
            title: 'Aas, Norbert',
            publisher: 'Africa Multiple Cluster of Excellence',
            url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/778',
            genre: 'Person record',
            archive: 'AMIRA',
            updated: IssuedDate::parse('2026-06-09'),
        );

        $ris = $export->serialize($record, 'ris');
        $this->assertTrue(str_starts_with($ris, "TY  - ELEC\r\n"), 'RIS web page');
        $this->assertTrue(str_contains($ris, "T2  - AMIRA\r\n"), 'the site is the secondary title');
        $this->assertTrue(str_contains($ris, "M3  - Person record\r\n"), 'the record type is the website type');
        $this->assertTrue(str_contains($ris, "DA  - 2026/06/09\r\n"), 'dated by its last change');
        $this->assertTrue(!str_contains($ris, 'AU  - '), 'an entry has no author');

        $csl = json_decode((string) $export->serialize($record, 'csljson'), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame('webpage', $csl['type']);
        $this->assertSame('AMIRA', $csl['container-title']);
        $this->assertSame('Person record', $csl['genre']);
        $this->assertSame([[2026, 6, 9]], $csl['issued']['date-parts']);

        $bibtex = $export->serialize($record, 'bibtex');
        $this->assertTrue(str_starts_with($bibtex, '@online{item-778,'), 'biblatex @online');
        $this->assertTrue((bool) preg_match('/organization\s+= \{AMIRA\}/', $bibtex), 'the database hosts the page');
        $this->assertTrue((bool) preg_match('/date\s+= \{2026-06-09\}/', $bibtex), 'biblatex full date');
    }

    public function testABookExportsItsSeriesAsASeriesNotAContainer(): void
    {
        $export = new CitationExport();
        $record = new CitationRecord(
            id: 1,
            kind: CitationKind::Book,
            title: 'Writing the Slave Trade Trauma',
            container: 'Afrikanische Literaturen',
            publisher: 'Akademische Verlagsanstalt',
            volume: '1',
            isbn: '978-3-96091-668-0',
            place: 'München',
        );

        $csl = json_decode((string) $export->serialize($record, 'csljson'), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame('Afrikanische Literaturen', $csl['collection-title']);
        $this->assertSame('1', $csl['collection-number']);
        $this->assertTrue(!isset($csl['container-title']), 'a book sits in no container');
        $this->assertSame('München', $csl['publisher-place']);

        $bibtex = $export->serialize($record, 'bibtex');
        $this->assertTrue((bool) preg_match('/series\s+= \{Afrikanische Literaturen\}/', $bibtex), 'BibTeX series');
        $this->assertTrue((bool) preg_match('/address\s+= \{München\}/', $bibtex), 'BibTeX address');

        $ris = $export->serialize($record, 'ris');
        $this->assertTrue(str_contains($ris, "T2  - Afrikanische Literaturen\r\n"), 'Zotero reads a book T2 as its series');
        $this->assertTrue(str_contains($ris, "SN  - 978-3-96091-668-0\r\n"), 'the ISBN');
    }

    public function testAResearchItemExportsTheArchiveThatHoldsIt(): void
    {
        $record = new CitationRecord(
            id: 8179,
            kind: CitationKind::Item,
            title: 'Untitled',
            container: 'Liberian broadcasts',
            accession: 'abn-99-009f',
            genre: 'Moving image',
            archive: 'AMIRA',
        );
        $export = new CitationExport();

        $ris = $export->serialize($record, 'ris');
        $this->assertTrue(str_contains($ris, "DB  - AMIRA\r\n") && str_contains($ris, "AN  - abn-99-009f\r\n"), 'archive and accession');

        $csl = json_decode((string) $export->serialize($record, 'csljson'), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame('AMIRA', $csl['archive']);
        $this->assertSame('abn-99-009f', $csl['archive_location']);
        $this->assertSame('Moving image', $csl['genre']);
    }

    public function testThePlainTextDownloadIsTheDefaultStyleWithoutMarkup(): void
    {
        $export = new CitationExport(new CitationFormatter(), 'chicago');

        $text = $export->serialize(self::exportFixture(), 'txt');

        $this->assertTrue(str_starts_with($text, 'Triaud, Jean-Louis.'), 'Chicago, author first: ' . $text);
        $this->assertTrue(!str_contains($text, '<') && !str_contains($text, '&'), 'no markup or entities');
        $this->assertTrue(str_contains($text, '“Muslim Politics.”'), 'typographic quotes survive as UTF-8');
        $this->assertTrue(str_ends_with($text, "https://doi.org/10.1234/ab_cd.\n"), 'one line, newline-terminated');
        $this->assertSame('abz-01-0000.txt', $export->filename(self::exportFixture(), 'txt'), 'deterministic filename');
        $this->assertSame('text/plain; charset=utf-8', CitationExport::FORMATS['txt'][1]);
        $this->assertSame($text, $export->serialize(self::exportFixture(), 'txt'), 'deterministic body');
    }

    public function testCitationDownloadsRevalidateByETagThenByLastModified(): void
    {
        $etag = HttpCache::etag('body');
        $modified = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame('Thu, 01 Oct 2026 12:00:00 GMT', HttpCache::lastModified($modified));
        $this->assertTrue(HttpCache::notModified($etag, $modified, $etag, null));
        $this->assertTrue(HttpCache::notModified($etag, $modified, '"other", W/' . $etag, null));
        $this->assertTrue(!HttpCache::notModified($etag, $modified, '"other"', 'Thu, 01 Oct 2026 13:00:00 GMT'), 'If-None-Match wins');
        $this->assertTrue(HttpCache::notModified($etag, $modified, null, 'Thu, 01 Oct 2026 12:00:00 GMT'));
        $this->assertTrue(!HttpCache::notModified($etag, $modified, null, 'Thu, 01 Oct 2026 11:59:59 GMT'));
        $this->assertTrue(!HttpCache::notModified($etag, null, null, 'Thu, 01 Oct 2026 12:00:00 GMT'));
    }
}
