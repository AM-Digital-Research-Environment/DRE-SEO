<?php
declare(strict_types=1);

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationKind;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/CitationExport.php';

function exportFixture(CitationKind $kind = CitationKind::Article): CitationRecord
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
    );
}

test('BibTeX carries the entry type, cite key and journal fields', function (): void {
    $bibtex = (new CitationExport())->serialize(exportFixture(), 'bibtex');

    assertTrue(str_starts_with($bibtex, '@article{abz-01-0000,'), 'entry type and cite key');
    assertTrue(str_contains($bibtex, 'author    = {Triaud, Jean-Louis}'), 'inverted author');
    assertTrue(str_contains($bibtex, 'editor    = {Robinson, David}'), 'editor');
    assertTrue(str_contains($bibtex, 'title     = {{Muslim Politics}}'), 'title case is brace-protected');
    assertTrue(str_contains($bibtex, 'journal   = {Journal of African History}'), 'container is the journal');
    assertTrue(str_contains($bibtex, 'year      = {2018}'), 'year');
    assertTrue(str_contains($bibtex, 'pages     = {185--209}'), 'en-dashed page range');
    assertTrue(str_contains($bibtex, 'issn      = {1234-5678}'), 'issn');
    assertTrue(str_contains($bibtex, 'keywords  = {Islam, Benin}'), 'keywords');
    assertTrue(str_ends_with($bibtex, "}\n"), 'the entry is closed');
});

test('BibTeX leaves DOI and URL verbatim but escapes LaTeX specials elsewhere', function (): void {
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
    assertTrue(str_contains($bibtex, 'doi       = {10.1234/ab_cd}'), 'the DOI is verbatim');
    assertTrue(str_contains($bibtex, 'url       = {https://example.test/s/amira/item/1?x=a_b}'), 'the URL is verbatim');
    // …but must be escaped in a title, where LaTeX would read it as a subscript.
    assertTrue(str_contains($bibtex, 'Faith \\& Power\\_now'), 'LaTeX specials are escaped in text fields');
    // An institution stays one braced field so BibTeX cannot split it.
    assertTrue(str_contains($bibtex, 'author    = {{Africa Multiple Cluster of Excellence}}'), 'institution braced');
});

test('BibTeX routes the container by kind', function (): void {
    $export = new CitationExport();
    // Field names are column-aligned to the widest key in the entry, so match
    // the field rather than a fixed run of spaces.
    $field = static fn (string $bibtex, string $name, string $value): bool
        => (bool) preg_match('/\b' . $name . '\s+= \{' . preg_quote($value, '/') . '\}/', $bibtex);

    $conference = $export->serialize(exportFixture(CitationKind::Conference), 'bibtex');
    assertTrue(str_starts_with($conference, '@inproceedings{'), 'conference entry type');
    assertTrue($field($conference, 'booktitle', 'Journal of African History'), 'proceedings in booktitle');

    $thesis = $export->serialize(exportFixture(CitationKind::Thesis), 'bibtex');
    assertTrue(str_starts_with($thesis, '@phdthesis{'), 'thesis entry type');
    assertTrue($field($thesis, 'school', 'Brill'), 'the awarding institution is the school');

    $report = $export->serialize(exportFixture(CitationKind::Report), 'bibtex');
    assertTrue(str_starts_with($report, '@techreport{'), 'report entry type');
    assertTrue($field($report, 'institution', 'Brill'), 'the issuing body is the institution');

    assertTrue(str_starts_with($export->serialize(exportFixture(CitationKind::Dataset), 'bibtex'), '@dataset{'));
});

test('a record with no accession id falls back to an item cite key', function (): void {
    $export = new CitationExport();
    $record = new CitationRecord(id: 42, kind: CitationKind::Item, title: 'A record');

    assertTrue(str_starts_with($export->serialize($record, 'bibtex'), '@misc{item-42,'));
    assertSameValue('item-42.bib', $export->filename($record, 'bibtex'));
});

test('RIS is CRLF-delimited, typed and terminated', function (): void {
    $ris = (new CitationExport())->serialize(exportFixture(), 'ris');

    assertTrue(str_starts_with($ris, "TY  - JOUR\r\n"), 'the type tag opens the record');
    assertTrue(str_contains($ris, "AU  - Triaud, Jean-Louis\r\n"), 'author');
    assertTrue(str_contains($ris, "ED  - Robinson, David\r\n"), 'editor');
    assertTrue(str_contains($ris, "T2  - Journal of African History\r\n"), 'container in T2');
    assertTrue(str_contains($ris, "PY  - 2018\r\n"), 'year');
    assertTrue(str_contains($ris, "DA  - 2018/12/07\r\n"), 'full date, zero-padded');
    assertTrue(str_contains($ris, "SP  - 185\r\n"), 'start page');
    assertTrue(str_contains($ris, "EP  - 209\r\n"), 'end page');
    assertTrue(str_contains($ris, "SN  - 1234-5678\r\n"), 'issn in the standard-number slot');
    assertTrue(str_contains($ris, "DO  - 10.1234/ab_cd\r\n"), 'doi');
    assertTrue(str_contains($ris, "KW  - Islam\r\n") && str_contains($ris, "KW  - Benin\r\n"), 'one KW per keyword');
    assertTrue(str_ends_with($ris, "ER  - \r\n"), 'the end tag closes the record');
});

test('RIS collapses newlines inside a value', function (): void {
    $record = new CitationRecord(
        id: 1,
        kind: CitationKind::Item,
        title: 'A record',
        abstract: "First line.\nSecond line."
    );
    $ris = (new CitationExport())->serialize($record, 'ris');

    assertTrue(str_contains($ris, 'AB  - First line. Second line.'), 'a value stays on one line');
});

test('CSL-JSON is an array of one typed item', function (): void {
    $json = (new CitationExport())->serialize(exportFixture(), 'csljson');
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    assertTrue(is_array($decoded) && count($decoded) === 1, 'CSL-JSON is an array of items');
    $item = $decoded[0];
    assertSameValue('abz-01-0000', $item['id']);
    assertSameValue('article-journal', $item['type']);
    assertSameValue('Muslim Politics', $item['title']);
    assertSameValue('Triaud', $item['author'][0]['family']);
    assertSameValue('Jean-Louis', $item['author'][0]['given']);
    assertSameValue('Journal of African History', $item['container-title']);
    assertSameValue([[2018, 12, 7]], $item['issued']['date-parts']);
    assertSameValue('185-209', $item['page']);
    assertSameValue('1234-5678', $item['ISSN']);
    assertSameValue('10.1234/ab_cd', $item['DOI']);
});

test('CSL-JSON keeps an institution as a literal name', function (): void {
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

    assertSameValue(
        ['literal' => 'Africa Multiple Cluster of Excellence'],
        $decoded[0]['author'][0]
    );
});

test('a date-less record omits the issued block entirely', function (): void {
    $record = new CitationRecord(id: 1, kind: CitationKind::Item, title: 'A record');
    $decoded = json_decode(
        (string) (new CitationExport())->serialize($record, 'csljson'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    assertTrue(!array_key_exists('issued', $decoded[0]), 'no date means no date-parts');
    assertTrue(!str_contains((string) (new CitationExport())->serialize($record, 'ris'), 'PY  - '));
});

test('download filenames are sanitised and extension-correct', function (): void {
    $export = new CitationExport();
    $record = exportFixture();

    assertSameValue('abz-01-0000.bib', $export->filename($record, 'bibtex'));
    assertSameValue('abz-01-0000.ris', $export->filename($record, 'ris'));
    assertSameValue('abz-01-0000.json', $export->filename($record, 'csljson'));

    $awkward = new CitationRecord(id: 7, kind: CitationKind::Item, accession: 'a b/c"d');
    $name = $export->filename($awkward, 'bibtex');
    assertTrue((bool) preg_match('/^[A-Za-z0-9._-]+$/', $name), "unsafe filename: {$name}");
});

test('an unknown format serialises to nothing', function (): void {
    assertSameValue(null, (new CitationExport())->serialize(exportFixture(), 'endnote'));
});
