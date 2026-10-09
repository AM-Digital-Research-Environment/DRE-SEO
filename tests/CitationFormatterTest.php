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
        'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em> 42, no. 3 (2001): 185–209.'
            . ' <a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/1">https://data.africamultiple.uni-bayreuth.de/s/amira/item/1</a>.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Triaud, J.-L. (2001). Muslim Politics. <em>Journal of African History</em>, <em>42</em>(3), 185–209.'
            . ' <a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/1">https://data.africamultiple.uni-bayreuth.de/s/amira/item/1</a>.',
        $formatter->format($record, 'apa')
    );
    assertSameValue(
        // MLA closes the container with the location, after a comma.
        'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em>, vol. 42, no. 3, 2001, pp. 185–209,'
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
        'Miran, Marie. “The Politics of Piety.” In <em>Muslim Societies</em>, edited by David Robinson, 55–80. Brill, 2005.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Miran, M. (2005). The Politics of Piety. In D. Robinson (Ed.), <em>Muslim Societies</em> (pp. 55–80). Brill.',
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
        'degree' => 'doctoral',
    ]);

    assertSameValue(
        'Madore, Frédérick. “Islam politique.” PhD diss., Université Laval, 2018.',
        $formatter->format($record, 'chicago')
    );
    // APA brackets the degree and institution onto the title.
    assertSameValue(
        'Madore, F. (2018). <em>Islam politique</em> [Doctoral dissertation, Université Laval].',
        $formatter->format($record, 'apa')
    );
    // MLA italicises the title and names institution and degree, so a
    // dissertation is not read as a published book.
    assertSameValue(
        'Madore, Frédérick. <em>Islam politique</em>. 2018. Université Laval, PhD dissertation.',
        $formatter->format($record, 'mla')
    );
});

test('a thesis below or beside the doctorate is not called a PhD', function (): void {
    // The master's, bachelor's and habilitation templates share the doctoral
    // template's 'thesis' kind; only the degree tells them apart.
    $formatter = new CitationFormatter();
    $thesis = static fn (?string $degree): CitationRecord => citationFixture(CitationKind::Thesis, [
        'title' => 'Islam politique',
        'authors' => [Creator::parse('Frédérick Madore', false)],
        'publisher' => 'Universität Bayreuth',
        'issued' => IssuedDate::parse('2018'),
        'url' => null,
        'degree' => $degree,
    ]);

    assertSameValue(
        'Madore, Frédérick. “Islam politique.” Master’s thesis, Universität Bayreuth, 2018.',
        $formatter->format($thesis('masters'), 'chicago')
    );
    assertSameValue(
        'Madore, F. (2018). <em>Islam politique</em> [Bachelor’s thesis, Universität Bayreuth].',
        $formatter->format($thesis('bachelors'), 'apa')
    );
    assertSameValue(
        'Madore, Frédérick. <em>Islam politique</em>. 2018. Universität Bayreuth, mémoire de master.',
        $formatter->format($thesis('masters'), 'mla', 'fr')
    );
    // No degree on record (a habilitation): a plain thesis, never a PhD.
    assertSameValue(
        'Madore, Frédérick. “Islam politique.” Thesis, Universität Bayreuth, 2018.',
        $formatter->format($thesis(null), 'chicago')
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
        'Triaud, J.-L. (2001). <em>Survey responses</em> [Data set]. University of Bayreuth.',
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
        // MLA abbreviates month names longer than four letters.
        'Triaud, Jean-Louis. “Episode 4.” <em>Africa Multiple Podcast</em>, 7 Dec. 2018.',
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

test('an authority record is cited as an entry of the database', function (): void {
    $formatter = new CitationFormatter();
    $record = new CitationRecord(
        id: 778,
        kind: CitationKind::Person,
        title: 'Aas, Norbert',
        publisher: 'Africa Multiple Cluster of Excellence, University of Bayreuth',
        url: 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/778',
        genre: 'Person record',
        archive: 'Africa Multiple Interactive Research Atlas (AMIRA)',
        updated: IssuedDate::parse('2026-06-09'),
    );
    $link = '<a href="https://data.africamultiple.uni-bayreuth.de/s/amira/item/778">https://data.africamultiple.uni-bayreuth.de/s/amira/item/778</a>';

    assertSameValue(
        '“Aas, Norbert.” Person record. Africa Multiple Interactive Research Atlas (AMIRA).'
            . ' Africa Multiple Cluster of Excellence, University of Bayreuth. Last modified June 9, 2026. ' . $link . '.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Aas, Norbert [Person record]. (2026, June 9). In <em>Africa Multiple Interactive Research Atlas (AMIRA)</em>.'
            . ' Africa Multiple Cluster of Excellence, University of Bayreuth. ' . $link . '.',
        $formatter->format($record, 'apa')
    );
    assertSameValue(
        '“Aas, Norbert.” <em>Africa Multiple Interactive Research Atlas (AMIRA)</em>,'
            . ' Africa Multiple Cluster of Excellence, University of Bayreuth, 9 June 2026, ' . $link . '.',
        $formatter->format($record, 'mla')
    );
    $french = $formatter->format($record, 'chicago', 'fr');
    assertTrue(
        str_contains($french, 'Notice de personne. ') && str_contains($french, 'Dernière modification le 9 juin 2026.'),
        'an entry reads in French under a French locale'
    );
    // A record with no modification date still cites, undated in APA.
    $undated = new CitationRecord(id: 1, kind: CitationKind::Authority, title: 'Gender');
    assertSameValue('Gender [Authority record]. (n.d.).', $formatter->format($undated, 'apa'));
});

test('a quoted title keeps one closing period and nests its own quotes', function (): void {
    $formatter = new CitationFormatter();
    $podcast = citationFixture(CitationKind::Podcast, [
        'title' => 'Parvati Raghuram: "Four Moves for Reshaping Migration Categories."',
        'container' => 'On the go',
        'url' => null,
    ]);
    assertTrue(
        str_contains(
            $formatter->format($podcast, 'chicago'),
            '“Parvati Raghuram: ‘Four Moves for Reshaping Migration Categories.’” <em>On the go</em>'
        ),
        'inner double quotes become single, and no period is added after one'
    );

    $question = citationFixture(CitationKind::Article, ['title' => 'Who Speaks?', 'container' => 'J', 'url' => null]);
    assertTrue(str_contains($formatter->format($question, 'chicago'), '“Who Speaks?” <em>J</em>'));

    $quoting = citationFixture(CitationKind::Article, [
        'title' => 'Lee on Nyabola, ‘Strange and Difficult Times’',
        'container' => 'H-Net',
        'url' => null,
    ]);
    assertTrue(
        str_contains($formatter->format($quoting, 'chicago'), '“Lee on Nyabola, ‘Strange and Difficult Times.’”'),
        'the period goes inside a closing quotation'
    );
});

test('an article in an unnumbered periodical carries its full date', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Article, [
        'title' => 'Genesene Menschen als Ressource?',
        'authors' => [Creator::parse('Ouma, Stefan', false)],
        'container' => 'Frankfurter Rundschau',
        'issued' => IssuedDate::parse('2020-05-01'),
        'url' => null,
    ]);

    assertSameValue(
        'Ouma, Stefan. “Genesene Menschen als Ressource?” <em>Frankfurter Rundschau</em>, May 1, 2020.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Ouma, S. (2020, May 1). Genesene Menschen als Ressource? <em>Frankfurter Rundschau</em>.',
        $formatter->format($record, 'apa')
    );
});

test('a conference paper without proceedings is cited as presented', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Conference, [
        'title' => 'Intonation in advice-giving',
        'authors' => [Creator::parse('Otundo, Billian', false)],
        'issued' => IssuedDate::parse('2022-05'),
        'event' => 'Speech Prosody 2022, University of Lisbon, 23-26 May 2022',
        'url' => null,
    ]);

    assertSameValue(
        'Otundo, Billian. “Intonation in advice-giving.” Paper presented at Speech Prosody 2022, University of Lisbon, 23-26 May 2022.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Otundo, B. (2022, May). <em>Intonation in advice-giving</em> [Paper presentation]. Speech Prosody 2022, University of Lisbon, 23-26 May 2022.',
        $formatter->format($record, 'apa')
    );
    // Never "In <its own title>".
    assertTrue(!str_contains($formatter->format($record, 'chicago'), 'In <em>'));
});

test('a YouTube video is cited on its platform with its full date', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Video, [
        'title' => 'Augmented island topographies',
        'authors' => [],
        'issued' => IssuedDate::parse('2022-07-08'),
        'platform' => 'YouTube',
        'url' => null,
    ]);

    assertSameValue('“Augmented island topographies.” YouTube video, July 8, 2022.', $formatter->format($record, 'chicago'));
    assertSameValue('<em>Augmented island topographies</em> [Video]. (2022, July 8). YouTube.', $formatter->format($record, 'apa'));
    assertSameValue('“Augmented island topographies.” <em>YouTube</em>, 8 July 2022.', $formatter->format($record, 'mla'));
});

test('a podcast episode credits its host and numbers the episode', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Podcast, [
        'title' => 'Four Moves',
        'authors' => [Creator::parse('Akese, Grace Abena', false)],
        'authorRole' => 'host',
        'number' => '1',
        'container' => 'On the go',
        'issued' => IssuedDate::parse('2021'),
        'url' => null,
    ]);

    assertSameValue(
        'Akese, Grace Abena, host. “Four Moves.” <em>On the go</em>, episode 1, 2021.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Akese, G. A. (Host). (2021). Four Moves (No. 1) [Audio podcast episode]. In <em>On the go</em>.',
        $formatter->format($record, 'apa')
    );
});

test('a research item is cited from its collection and archive', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Item, [
        'title' => 'From the Editor',
        'authors' => [Creator::parse('Watkins, Lee', false)],
        'issued' => IssuedDate::parse('2025'),
        'container' => 'International Library of African Music',
        'archive' => 'AMIRA',
        'url' => null,
    ]);

    assertSameValue(
        'Watkins, Lee. <em>From the Editor</em>. 2025. International Library of African Music. AMIRA.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Watkins, L. (2025). <em>From the Editor</em>. International Library of African Music, AMIRA.',
        $formatter->format($record, 'apa')
    );
    assertSameValue(
        'Watkins, Lee. <em>From the Editor</em>. 2025. International Library of African Music, AMIRA.',
        $formatter->format($record, 'mla')
    );
});

test('a book in a numbered series names the series in Chicago only', function (): void {
    $formatter = new CitationFormatter();
    $record = citationFixture(CitationKind::Book, [
        'title' => 'Writing the Slave Trade Trauma in Francophone Africa',
        'authors' => [Creator::parse('Boudjekeu, Thierry', false)],
        'container' => 'Afrikanische Literaturen',
        'volume' => '1',
        'publisher' => 'Akademische Verlagsanstalt',
        'issued' => IssuedDate::parse('2026'),
        'url' => null,
    ]);

    assertSameValue(
        'Boudjekeu, Thierry. <em>Writing the Slave Trade Trauma in Francophone Africa</em>. Afrikanische Literaturen 1. Akademische Verlagsanstalt, 2026.',
        $formatter->format($record, 'chicago')
    );
    assertSameValue(
        'Boudjekeu, T. (2026). <em>Writing the Slave Trade Trauma in Francophone Africa</em>. Akademische Verlagsanstalt.',
        $formatter->format($record, 'apa')
    );
    assertSameValue(
        'Boudjekeu, Thierry. <em>Writing the Slave Trade Trauma in Francophone Africa</em>. Akademische Verlagsanstalt, 2026.',
        $formatter->format($record, 'mla')
    );
});
