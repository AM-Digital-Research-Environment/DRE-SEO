<?php
declare(strict_types=1);

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\CitationKind;

require_once __DIR__ . '/../src/Service/CitationKind.php';
require_once __DIR__ . '/../src/Service/Citation/Creator.php';
require_once __DIR__ . '/../src/Service/Citation/IssuedDate.php';
require_once __DIR__ . '/../src/Service/Citation/CitationRecord.php';
require_once __DIR__ . '/../src/Service/CitationFormatter.php';

/** @param array<string,mixed> $overrides */
function citationFixture(CitationKind $kind, array $overrides = []): CitationRecord
{
    $data = array_merge([
        'id' => 1,
        'kind' => $kind,
        'title' => 'Muslim Politics',
        'authors' => [Creator::parse('Jean-Louis Triaud', false)],
        'issued' => IssuedDate::parse('2001'),
        'url' => 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/1',
    ], $overrides);

    return new CitationRecord(...$data);
}

test('a journal article reads correctly in all three styles', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Article, [
        'container' => 'Journal of African History',
        'volume' => '42',
        'issue' => '3',
        'pageFirst' => '185',
        'pageLast' => '209',
    ]);

    assertSameValue(
        'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em> 42, no. 3 (2001): 185-209.'
            . ' <a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/1">https://data.africamultiple.uni-bayreuth.de/s/amira/item/1</a>.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Triaud, J.-L. (2001). Muslim Politics. <em>Journal of African History</em>, <em>42</em>(3), 185-209.'
            . ' <a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/1">https://data.africamultiple.uni-bayreuth.de/s/amira/item/1</a>.',
        $formatter->format($record, 'apa')
    );
    assertSameValue(
        'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em>, vol. 42, no. 3, 2001, pp. 185-209.'
            . ' <a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/1">https://data.africamultiple.uni-bayreuth.de/s/amira/item/1</a>.',
        $formatter->format($record, 'mla')
    );
});

test('a DOI replaces the item page in the link segment', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Article, [
        'container' => 'Journal of African History',
        'doi' => '10.1234/abcd',
    ]);

    assertTrue(
        str_contains($formatter->format($record, 'chicago'), 'https://doi.org/10.1234/abcd'),
        'the DOI is the preferred locator'
    );
});

test('a book chapter cites its book, its editors and its publisher', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Chapter, [
        'title' => 'The Politics of Piety',
        'authors' => [Creator::parse('Marie Miran', false)],
        'editors' => [Creator::parse('David Robinson', false)],
        'bookTitle' => 'Muslim Societies',
        'publisher' => 'Brill',
        'issued' => IssuedDate::parse('2005'),
        'pageFirst' => '55',
        'pageLast' => '80',
        'url' => null,
    ]);

    assertSameValue(
        'Miran, Marie. “The Politics of Piety.” In <em>Muslim Societies</em>, edited by David Robinson, 55-80. Brill, 2005.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Miran, M. (2005). The Politics of Piety. In D. Robinson (Ed.), <em>Muslim Societies</em> (pp. 55-80). Brill.',
        $formatter->format($record, 'apa')
    );
});

test('a conference paper is cited inside its proceedings', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Conference, [
        'title' => 'Mapping research data',
        'container' => 'Proceedings of the Digital Humanities Conference',
        'publisher' => 'ADHO',
        'url' => null,
    ]);

    assertSameValue(
        'Triaud, Jean-Louis. “Mapping research data.” In <em>Proceedings of the Digital Humanities Conference</em>. ADHO, 2001.',
        $formatter->format($record, 'chicago')
    );
});

test('a doctoral thesis names its awarding institution', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Thesis, [
        'title' => 'Islam politique',
        'authors' => [Creator::parse('Frédérick Madore', false)],
        'publisher' => 'Université Laval',
        'issued' => IssuedDate::parse('2018'),
        'url' => null,
    ]);

    assertSameValue(
        'Madore, Frédérick. “Islam politique.” PhD diss., Université Laval, 2018.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Madore, F. (2018). <em>Islam politique</em>. [PhD diss., Université Laval].',
        $formatter->format($record, 'apa')
    );
    // MLA names the degree, so a dissertation is not read as a published book.
    assertSameValue(
        'Madore, Frédérick. “Islam politique.” Université Laval, 2018. PhD diss.',
        $formatter->format($record, 'mla')
    );
});

test('a dataset and a video carry a form descriptor', function (): void {
    $formatter = new CitationFormatter();

    $dataset = citationFixture(CitationKind::Dataset, [
        'title' => 'Survey responses',
        'publisher' => 'University of Bayreuth',
        'url' => null,
    ]);
    assertSameValue(
        'Triaud, Jean-Louis. <em>Survey responses</em>. Data set. University of Bayreuth, 2001.',
        $formatter->format($dataset, 'chicago')
    );
    assertSameValue(
        'Triaud, J.-L. (2001). <em>Survey responses</em>. [Data set]. University of Bayreuth.',
        $formatter->format($dataset, 'apa')
    );

    $video = citationFixture(CitationKind::Video, ['title' => 'Opening lecture', 'url' => null]);
    assertTrue(str_contains($formatter->format($video, 'chicago'), 'Video.'), 'a video is marked as one');
});

test('a podcast episode carries its series and full date', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Podcast, [
        'title' => 'Episode 4',
        'container' => 'Africa Multiple Podcast',
        'issued' => IssuedDate::parse('2018-12-07'),
        'url' => null,
    ]);

    assertSameValue(
        'Triaud, Jean-Louis. “Episode 4.” <em>Africa Multiple Podcast</em>, December 7, 2018.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Triaud, Jean-Louis. “Episode 4.” <em>Africa Multiple Podcast</em>, 7 December 2018.',
        $formatter->format($record, 'mla')
    );
});

test('a record with no date is marked n.d. in APA and drops the year elsewhere', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Book, [
        'title' => 'A book',
        'issued' => IssuedDate::unknown(),
        'publisher' => 'Brill',
        'url' => null,
    ]);

    assertSameValue('Triaud, J.-L. (n.d.). <em>A book</em>. Brill.', $formatter->format($record, 'apa'));
    assertSameValue('Triaud, Jean-Louis. <em>A book</em>. Brill.', $formatter->format($record, 'chicago'));
});

test('a record with no author leads with its title', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Item, [
        'title' => 'A field recording',
        'authors' => [],
        'url' => null,
    ]);

    assertSameValue('<em>A field recording</em>. 2001.', $formatter->format($record, 'chicago'));
    // APA moves the date after the title when the creator slot is empty.
    assertSameValue('<em>A field recording</em>. (2001).', $formatter->format($record, 'apa'));
});

test('an edited volume with no authors credits its editors as editors', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Book, [
        'title' => 'Muslim Societies',
        'authors' => [],
        'editors' => [Creator::parse('David Robinson', false), Creator::parse('Jean-Louis Triaud', false)],
        'publisher' => 'Brill',
        'url' => null,
    ]);

    assertSameValue(
        'Robinson, David, and Jean-Louis Triaud, eds. <em>Muslim Societies</em>. Brill, 2001.',
        $formatter->format($record, 'chicago')
    );
});

test('institutional authors are never inverted', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Report, [
        'title' => 'Annual report',
        'authors' => [Creator::parse('Africa Multiple Cluster of Excellence', true)],
        'publisher' => 'University of Bayreuth',
        'url' => null,
    ]);

    assertSameValue(
        'Africa Multiple Cluster of Excellence. <em>Annual report</em>. University of Bayreuth, 2001.',
        $formatter->format($record, 'chicago')
    );
});

test('MLA abbreviates three or more authors, Chicago lists them', function (): void {
    $formatter = new CitationFormatter();
    $three = [
        Creator::parse('Jean-Louis Triaud', false),
        Creator::parse('David Robinson', false),
        Creator::parse('Marie Miran', false),
    ];
    $record = citationFixture(CitationKind::Book, [
        'title' => 'A book',
        'authors' => $three,
        'publisher' => 'Brill',
        'url' => null,
    ]);

    assertSameValue(
        'Triaud, Jean-Louis, et al. <em>A book</em>. Brill, 2001.',
        $formatter->format($record, 'mla')
    );
    assertSameValue(
        'Triaud, Jean-Louis, David Robinson, and Marie Miran. <em>A book</em>. Brill, 2001.',
        $formatter->format($record, 'chicago')
    );
});

test('French renders connectives and months in French', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Podcast, [
        'title' => 'Épisode 4',
        'container' => 'Podcast',
        'issued' => IssuedDate::parse('2018-12-07'),
        'url' => null,
    ]);

    assertSameValue(
        'Triaud, Jean-Louis. “Épisode 4.” <em>Podcast</em>, 7 décembre 2018.',
        $formatter->format($record, 'chicago', 'fr')
    );

    $chapter = citationFixture(CitationKind::Chapter, [
        'title' => 'Un chapitre',
        'editors' => [Creator::parse('David Robinson', false)],
        'bookTitle' => 'Un livre',
        'publisher' => 'Brill',
        'url' => null,
    ]);
    assertTrue(
        str_contains($formatter->format($chapter, 'chicago', 'fr'), 'Dans <em>Un livre</em>, sous la dir. de'),
        'French uses "Dans" and "sous la dir. de"'
    );
});

test('an unknown style or locale falls back rather than failing', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Book, ['publisher' => 'Brill', 'url' => null]);

    assertSameValue(
        $formatter->format($record, 'chicago'),
        $formatter->format($record, 'harvard'),
    );
    assertSameValue(
        $formatter->format($record, 'chicago', 'en'),
        $formatter->format($record, 'chicago', 'de'),
    );
});

test('titles and names are HTML-escaped', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Book, [
        'title' => 'Faith & <script>alert(1)</script> Power',
        'publisher' => 'Brill & Sons',
        'url' => null,
    ]);
    $html = $formatter->format($record, 'chicago');

    assertTrue(!str_contains($html, '<script>'), 'markup in a value must not survive');
    assertTrue(str_contains($html, 'Faith &amp; '), 'ampersands are escaped');
    assertTrue(str_contains($html, 'Brill &amp; Sons'), 'the publisher is escaped too');
});

test('a record with no title at all still cites', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Item, ['title' => null, 'url' => null]);

    assertTrue(str_contains($formatter->format($record, 'chicago'), 'Untitled'));
    assertTrue(str_contains($formatter->format($record, 'chicago', 'fr'), 'Sans titre'));
});
