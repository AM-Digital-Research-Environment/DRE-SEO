<?php
declare(strict_types=1);

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationKind;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';

// ─── Creator ────────────────────────────────────────────────────────────────

test('a person name displayed "First Last" is split on the last token', function (): void {
    $creator = Creator::parse('Frédérick Madore', false);

    assertSameValue('Madore', $creator->family);
    assertSameValue('Frédérick', $creator->given);
    assertSameValue('Frédérick Madore', $creator->literal);
    assertTrue(!$creator->isInstitution);
    assertTrue(!$creator->isSingleField());
});

test('an already-inverted name is detected by its comma', function (): void {
    $creator = Creator::parse('Madore, Frédérick', false);

    assertSameValue('Madore', $creator->family);
    assertSameValue('Frédérick', $creator->given);
});

test('a multi-word given name keeps every token', function (): void {
    $creator = Creator::parse('Maria Elena Rodrigues', false);

    assertSameValue('Rodrigues', $creator->family);
    assertSameValue('Maria Elena', $creator->given);
});

test('an institution is never split or inverted', function (): void {
    $creator = Creator::parse('Africa Multiple Cluster of Excellence', true);

    assertSameValue(null, $creator->family);
    assertSameValue(null, $creator->given);
    assertSameValue('Africa Multiple Cluster of Excellence', $creator->literal);
    assertTrue($creator->isSingleField());
});

test('a single-token name becomes a family name with no given part', function (): void {
    $creator = Creator::parse('Plato', false);

    assertSameValue('Plato', $creator->family);
    assertSameValue(null, $creator->given);
    // It still has a family name, so it is not an unsplittable single field —
    // that is reserved for institutions and names with no family part at all.
    assertTrue(!$creator->isSingleField());
});

test('a creator survives a round trip through its array form', function (): void {
    foreach ([Creator::parse('Frédérick Madore', false), Creator::parse('DFG', true)] as $creator) {
        $back = Creator::fromArray($creator->toArray());
        assertSameValue($creator->family, $back->family);
        assertSameValue($creator->given, $back->given);
        assertSameValue($creator->literal, $back->literal);
        assertSameValue($creator->isInstitution, $back->isInstitution);
    }
});

// ─── IssuedDate ─────────────────────────────────────────────────────────────

test('a stored timestamp keeps whatever precision it carries', function (): void {
    $year = IssuedDate::parse('2018');
    assertSameValue(2018, $year->year);
    assertSameValue(null, $year->month);

    $month = IssuedDate::parse('2018-12');
    assertSameValue(2018, $month->year);
    assertSameValue(12, $month->month);
    assertSameValue(null, $month->day);

    $day = IssuedDate::parse('2018-12-07');
    assertSameValue(12, $day->month);
    assertSameValue(7, $day->day);
});

test('an unparseable date is preserved verbatim', function (): void {
    $date = IssuedDate::parse('n.d.');

    assertSameValue(null, $date->year);
    assertTrue(!$date->hasYear());
    assertSameValue('n.d.', $date->literal);
    assertSameValue('n.d.', $date->yearOrLiteral());
});

test('an empty date is unknown', function (): void {
    $date = IssuedDate::parse('   ');

    assertSameValue(null, $date->year);
    assertSameValue(null, $date->literal);
    assertSameValue(null, $date->yearOrLiteral());
});

// ─── CitationRecord ─────────────────────────────────────────────────────────

test('page ranges join, collapse and degrade', function (): void {
    assertSameValue('185-209', CitationRecord::joinPages('185', '209'));
    assertSameValue('185', CitationRecord::joinPages('185', '185'));
    assertSameValue('185', CitationRecord::joinPages('185', null));
    assertSameValue('209', CitationRecord::joinPages(null, '209'));
    assertSameValue(null, CitationRecord::joinPages(null, null));
});

test('the citation link prefers the DOI over the item page', function (): void {
    $withDoi = new CitationRecord(
        id: 1,
        kind: CitationKind::Article,
        doi: '10.1234/abcd',
        url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/1'
    );
    assertSameValue('https://doi.org/10.1234/abcd', $withDoi->link());

    $withoutDoi = new CitationRecord(
        id: 1,
        kind: CitationKind::Article,
        url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/1'
    );
    assertSameValue('https://data.africamultiple.uni-bayreuth.de/s/amira/item/1', $withoutDoi->link());

    assertSameValue(null, (new CitationRecord(id: 1, kind: CitationKind::Article))->link());
});

test('the theme-facing array form is complete and reversible', function (): void {
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
    foreach ([
        'id', 'kind', 'cslType', 'title', 'authors', 'editors', 'issued', 'container',
        'publisher', 'bookTitle', 'volume', 'issue', 'pageFirst', 'pageLast', 'issn',
        'isbn', 'doi', 'url', 'language', 'abstract', 'keywords', 'accession',
    ] as $key) {
        assertTrue(array_key_exists($key, $array), "the published contract is missing '{$key}'");
    }
    assertSameValue('chapter', $array['kind']);
    assertSameValue('chapter', $array['cslType']);
    assertSameValue('Madore', $array['authors'][0]['family']);

    $back = CitationRecord::fromArray($array);
    assertSameValue($record->title, $back->title);
    assertSameValue($record->kind, $back->kind);
    assertSameValue($record->bookTitle, $back->bookTitle);
    assertSameValue($record->issn, $back->issn);
    assertSameValue($record->isbn, $back->isbn);
    assertSameValue('55-80', $back->pageRange());
    assertSameValue(7, $back->issued->day);
    assertSameValue(['Islam', 'Benin'], $back->keywords);
});
