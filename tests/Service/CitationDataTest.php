<?php
declare(strict_types=1);

namespace DRESeo\Test\Service;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationKind;
use DRESeo\Service\CitationKindMap;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;

/**
 * CitationData: how an AMIRA record's values become a CitationRecord, kind by
 * kind, against the shipped template-to-kind map.
 */
final class CitationDataTest extends TestCase
{
    /** The production template→kind map, so the tests bind to the shipped config. */
    private static function citationDataService(): CitationData
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];

        return new CitationData(new CitationKindMap($citation['template_kinds'], $citation['default_kind']));
    }

    private static function literalValue(string $text, ?string $raw = null): ValueRepresentation
    {
        return new ValueRepresentation($text, null, null, $raw);
    }

    private static function linkedValue(string $title, int $templateId = 4): ValueRepresentation
    {
        return new ValueRepresentation('', new ItemRepresentation($title, $templateId));
    }

    public function testAJournalArticleMapsEveryPublicationField(): void
    {
        $item = new ItemRepresentation('Muslim Politics', 11, 'Journal Article', [
            'dcterms:title'    => [self::literalValue('Muslim Politics')],
            'dcterms:creator'  => [self::linkedValue('Jean-Louis Triaud')],
            'bibo:editorList'  => [self::linkedValue('David Robinson')],
            'dcterms:issued'   => [self::literalValue('7 December 2018', '2018-12-07')],
            'dcterms:isPartOf' => [self::linkedValue('Journal of African History', 23)],
            'dcterms:publisher' => [self::literalValue('Brill')],
            'bibo:volume'      => [self::literalValue('42')],
            'bibo:issue'       => [self::literalValue('3')],
            'bibo:pageStart'   => [self::literalValue('185')],
            'bibo:pageEnd'     => [self::literalValue('209')],
            'bibo:issn'        => [self::literalValue('1234-5678')],
            'bibo:doi'         => [self::literalValue('https://doi.org/10.1234/abcd')],
            'dcterms:language' => [self::linkedValue('English', 6)],
            'bibo:abstract'    => [self::literalValue('An abstract.')],
            'dcterms:subject'  => [self::linkedValue('Islam', 6), self::linkedValue('Benin', 6)],
            'dre:id'           => [self::literalValue('abz-01-0000')],
        ], null, 42);

        $record = self::citationDataService()->build($item, 'https://example.test/s/amira/item/42');

        $this->assertTrue($record !== null, 'a journal article is citable');
        $this->assertSame(CitationKind::Article, $record->kind);
        $this->assertSame('Muslim Politics', $record->title);
        $this->assertSame('Triaud', $record->authors[0]->family);
        $this->assertSame('Robinson', $record->editors[0]->family);
        // isPartOf is the container; publisher stays its own field.
        $this->assertSame('Journal of African History', $record->container);
        $this->assertSame('Brill', $record->publisher);
        $this->assertSame('42', $record->volume);
        $this->assertSame('1234-5678', $record->issn);
        // The DOI is normalised down to the bare identifier.
        $this->assertSame('10.1234/abcd', $record->doi);
        $this->assertSame('English', $record->language);
        $this->assertSame(['Islam', 'Benin'], $record->keywords);
        $this->assertSame('abz-01-0000', $record->accession);
        $this->assertSame('https://example.test/s/amira/item/42', $record->url);
        // The raw stored timestamp is preferred, so month and day survive.
        $this->assertSame(2018, $record->issued->year);
        $this->assertSame(12, $record->issued->month);
        $this->assertSame(7, $record->issued->day);
    }

    public function testAChapterReadsItsBookTitleFromIsPartOfNotTheContainerSlot(): void
    {
        $item = new ItemRepresentation('The Politics of Piety', 14, null, [
            'dcterms:title'    => [self::literalValue('The Politics of Piety')],
            'dcterms:isPartOf' => [self::linkedValue('Muslim Societies', 15)],
            'dcterms:publisher' => [self::literalValue('Brill')],
        ], null, 7);

        $record = self::citationDataService()->build($item);

        $this->assertSame(CitationKind::Chapter, $record->kind);
        $this->assertSame('Muslim Societies', $record->bookTitle);
        $this->assertSame(null, $record->container, 'a chapter has no separate container');
        $this->assertSame('Brill', $record->publisher);
    }

    public function testAThesisReadsItsDegreeFromTheResourceClass(): void
    {
        $data = self::citationDataService();
        // Template id => the class the AMIRA template assigns.
        $theses = [
            16 => ['fabio:DoctoralThesis', 'doctoral'],
            30 => ['fabio:Thesis', null],          // habilitation: the class names no degree
            31 => ['fabio:MastersThesis', 'masters'],
            32 => ['fabio:BachelorsThesis', 'bachelors'],
        ];
        foreach ($theses as $templateId => [$classTerm, $degree]) {
            $item = new ItemRepresentation('A thesis', $templateId, null, [
                'dcterms:title' => [self::literalValue('A thesis')],
            ], null, 1, $classTerm);
            $record = $data->build($item);

            $this->assertSame(CitationKind::Thesis, $record->kind, "template {$templateId}");
            $this->assertSame($degree, $record->degree, "template {$templateId}");
        }

        // Only theses carry a degree, whatever the class.
        $article = new ItemRepresentation('A', 11, null, [], null, 1, 'fabio:MastersThesis');
        $this->assertSame(null, $data->build($article)->degree);
    }

    public function testAuthorityRecordsAreNotWorksAndAreCitedAsDatabaseEntries(): void
    {
        $data = self::citationDataService();

        $authorities = [
            2  => ['organisation', 'Organisation record'],
            3  => ['location', 'Place record'],
            4  => ['person', 'Person record'],
            5  => ['project', 'Project record'],
            6  => ['subject heading', 'Authority record'],   // and languages, genres, sponsors — all template 6
            7  => ['section', 'Research section record'],
            23 => ['journal', 'Journal record'],            // a venue, not a work
        ];
        foreach ($authorities as $templateId => [$label, $genre]) {
            $item = new ItemRepresentation('An ' . $label, $templateId, null, [
                'dcterms:title' => [self::literalValue('An ' . $label)],
                // A record's own links are not authorship of the entry.
                'dcterms:creator' => [self::linkedValue('Someone')],
            ], null, 9, null, '2026-06-09T07:22:31+00:00');
            $this->assertTrue(!$data->isCitable($templateId), "{$label} must not be a citable work");

            $record = $data->build($item, 'https://example.test/s/amira/item/9', 'AMIRA');
            $this->assertTrue($record->isEntry(), "{$label} is cited as an entry");
            $this->assertSame($genre, $record->genre, $label);
            $this->assertSame('AMIRA', $record->archive, $label);
            $this->assertSame([], $record->authors, "an entry claims no author ({$label})");
            $this->assertSame(2026, $record->updated->year, $label);
            $this->assertSame(9, $record->updated->day, $label);
            $this->assertTrue(!$record->issued->hasYear(), "an entry has no publication date ({$label})");
        }
    }

    public function testAnEntryNamesThePublisherTheModuleIsConfiguredWith(): void
    {
        $config = require __DIR__ . '/../../config/module.config.php';
        $citation = $config['dre_seo']['citation'];
        $data = new CitationData(
            new CitationKindMap($citation['template_kinds'], $citation['default_kind']),
            'Africa Multiple Cluster of Excellence'
        );
        $person = new ItemRepresentation('Aas, Norbert', 4, 'Person', [], null, 778);

        $this->assertSame('Africa Multiple Cluster of Excellence', $data->build($person)->publisher);
        $this->assertSame('Aas, Norbert', $data->build($person)->title);
    }

    public function testAThesisNamesItsDegreeGrantorAsTheAwardingInstitution(): void
    {
        $item = new ItemRepresentation('Das Archiv als Heimat', 16, null, [
            'dcterms:title' => [self::literalValue('Das Archiv als Heimat : Die Fantasie Afrika')],
            'marcrel:dgg' => [self::literalValue('Universität Bayreuth, Bayreuth International Graduate School of African Studies')],
            'marcrel:pup' => [self::linkedValue('Bayreuth', 3)],
        ], null, 1, 'fabio:DoctoralThesis');

        $record = self::citationDataService()->build($item);

        $this->assertSame('Universität Bayreuth, Bayreuth International Graduate School of African Studies', $record->publisher);
        $this->assertSame('Bayreuth', $record->place);
        // ISBD's spaced colon is a cataloguing convention, not a citation one.
        $this->assertSame('Das Archiv als Heimat: Die Fantasie Afrika', $record->title);
    }

    public function testISBNsAndTextPageRangesAreReadWhereERefWritesThem(): void
    {
        $data = self::citationDataService();

        $entry = new ItemRepresentation('Internationalisierung', 27, null, [
            'bibo:isbn13' => [self::literalValue('978-3-8252-5425-4')],
            'bibo:pageStart' => [self::literalValue('98')],
            'bibo:pageEnd' => [self::literalValue('102')],
        ]);
        $record = $data->build($entry);
        $this->assertSame('978-3-8252-5425-4', $record->isbn);
        $this->assertSame('98-102', $record->pageRange());

        $paper = new ItemRepresentation('Externalisierung', 12, null, [
            'bibo:pages' => [self::literalValue('S. 23-38')],
        ]);
        $this->assertSame('23-38', $data->build($paper)->pageRange());
    }

    public function testWorksReadTheFieldsTheirStyleNeeds(): void
    {
        $data = self::citationDataService();

        // A podcast: episode number, and the host's role.
        $podcast = $data->build(new ItemRepresentation('Episode', 21, null, [
            'marcrel:hst' => [self::linkedValue('Akese, Grace Abena')],
            'bibo:number' => [self::literalValue('1')],
        ]));
        $this->assertSame('1', $podcast->number);
        $this->assertSame('host', $podcast->authorRole);

        // A YouTube video names its platform.
        $video = $data->build(new ItemRepresentation('A talk', 22, null, [
            'fabio:hasURL' => [new ValueRepresentation('', null, 'https://www.youtube.com/watch?v=VNHDMq_Vz9g')],
        ]));
        $this->assertSame('YouTube', $video->platform);

        // A conference paper without proceedings keeps where it was presented.
        $paper = $data->build(new ItemRepresentation('Intonation', 13, null, [
            'bibo:presentedAt' => [self::literalValue('Speech Prosody 2022, University of Lisbon')],
        ]));
        $this->assertSame('Speech Prosody 2022, University of Lisbon', $paper->event);
        $this->assertSame(null, $paper->container);

        // A research item is held in the archive and typed by its resource type;
        // a publication is only catalogued there.
        $researchItem = $data->build(new ItemRepresentation('Untitled', 10, null, [
            'dcterms:type' => [self::linkedValue('Moving image', 6)],
            'dcterms:isPartOf' => [self::linkedValue('Liberian broadcasts', 5)],
            'marcrel:cre' => [self::linkedValue('Gbenyon, Charles')],
        ]), null, 'AMIRA');
        $this->assertSame('Moving image', $researchItem->genre);
        $this->assertSame('AMIRA', $researchItem->archive);
        $this->assertSame('Liberian broadcasts', $researchItem->container);
        $this->assertSame('Gbenyon', $researchItem->authors[0]->family, 'marcrel:cre is a creator');
        $this->assertSame(null, $data->build(new ItemRepresentation('An article', 11), null, 'AMIRA')->archive);
    }

    public function testAnItemWithNoTemplateFallsBackToTheDefaultKindAndStillCites(): void
    {
        $item = new ItemRepresentation('An untemplated record', null, null, [
            'dcterms:title' => [self::literalValue('An untemplated record')],
        ]);

        $record = self::citationDataService()->build($item);

        $this->assertTrue($record !== null, 'no template still yields a citation');
        $this->assertSame(CitationKind::Item, $record->kind);
    }

    public function testAuthorsFallThroughTheRolePropertiesInOrder(): void
    {
        $data = self::citationDataService();

        // bibo:authorList wins over dcterms:creator…
        $both = new ItemRepresentation('A', 11, null, [
            'bibo:authorList' => [self::linkedValue('Ada Lovelace')],
            'dcterms:creator' => [self::linkedValue('Someone Else')],
        ]);
        $this->assertSame('Lovelace', $data->build($both)->authors[0]->family);

        // …and marcrel:aut is the last resort for a record that uses only it.
        $marcrelOnly = new ItemRepresentation('A', 11, null, [
            'marcrel:aut' => [self::linkedValue('Ada Lovelace')],
        ]);
        $this->assertSame('Lovelace', $data->build($marcrelOnly)->authors[0]->family);
    }

    public function testPodcastsCreditHostsAndVideosCreditSpeakers(): void
    {
        $data = self::citationDataService();

        $podcast = new ItemRepresentation('Episode 4', 21, null, [
            'dcterms:title' => [self::literalValue('Episode 4')],
            'marcrel:hst'   => [self::linkedValue('Ada Lovelace')],
            'marcrel:spk'   => [self::linkedValue('A Guest')],
        ]);
        $record = $data->build($podcast);
        $this->assertSame(CitationKind::Podcast, $record->kind);
        // The host role is read first, and wins outright — it is the podcaster.
        $this->assertSame('Lovelace', $record->authors[0]->family);
        $this->assertSame(1, count($record->authors));

        $video = new ItemRepresentation('Opening lecture', 22, null, [
            'dcterms:title' => [self::literalValue('Opening lecture')],
            'marcrel:spk'   => [self::linkedValue('Ada Lovelace')],
        ]);
        $videoRecord = $data->build($video);
        $this->assertSame(CitationKind::Video, $videoRecord->kind);
        $this->assertSame('Lovelace', $videoRecord->authors[0]->family);
    }

    public function testACreatorLinkedToAnOrganisationRecordIsKeptAsOneField(): void
    {
        $item = new ItemRepresentation('Annual report', 12, null, [
            'dcterms:title'   => [self::literalValue('Annual report')],
            'dcterms:creator' => [self::linkedValue('Africa Multiple Cluster of Excellence', 2)],
        ]);

        $creator = self::citationDataService()->build($item)->authors[0];

        $this->assertTrue($creator->isInstitution, 'a linked Organisation is an institution');
        $this->assertSame('Africa Multiple Cluster of Excellence', $creator->literal);
        $this->assertSame(null, $creator->family, 'an institution is never split');
    }

    public function testARecordWithNoCreatorsOrDateStillBuilds(): void
    {
        $item = new ItemRepresentation('A bare record', 10, null, [
            'dcterms:title' => [self::literalValue('A bare record')],
        ]);

        $record = self::citationDataService()->build($item);

        $this->assertSame('A bare record', $record->title);
        $this->assertSame([], $record->authors);
        $this->assertTrue(!$record->issued->hasYear());
    }

    public function testARecordWithNoDctermsTitleFallsBackToItsDisplayTitle(): void
    {
        $item = new ItemRepresentation('Display title', 10);

        $this->assertSame('Display title', self::citationDataService()->build($item)->title);
    }

    public function testTheDateTermsAreTriedInIssuedDateCreatedOrder(): void
    {
        $data = self::citationDataService();

        $dateOnly = new ItemRepresentation('A', 11, null, ['dcterms:date' => [self::literalValue('2001', '2001')]]);
        $this->assertSame(2001, $data->build($dateOnly)->issued->year);

        $both = new ItemRepresentation('A', 11, null, [
            'dcterms:issued'  => [self::literalValue('2018', '2018')],
            'dcterms:date'    => [self::literalValue('2001', '2001')],
            'dcterms:created' => [self::literalValue('1999', '1999')],
        ]);
        $this->assertSame(2018, $data->build($both)->issued->year, 'dcterms:issued wins');
    }

    public function testTheAccessionIdPrefersDreIdAndFallsBackToAPublicationId(): void
    {
        $data = self::citationDataService();

        // A research item carries dre:id, plus free-text identifiers that would make
        // a nonsense cite key — dre:id must win outright.
        $researchItem = new ItemRepresentation('A record', 10, null, [
            'dre:id' => [self::literalValue('abg-99-0000')],
            'dcterms:identifier' => [
                self::literalValue('Volume 8: Yoruba Architecture and Wall Painting'),
                self::literalValue('KG_00001_NachlassUB'),
            ],
        ]);
        $this->assertSame('abg-99-0000', $data->build($researchItem)->accession);

        // A publication has no dre:id; its ERef/EPub id is the usable one.
        foreach (['eref-95983', 'epub-1234'] as $publicationId) {
            $publication = new ItemRepresentation('An article', 11, null, [
                'dcterms:identifier' => [self::literalValue($publicationId)],
            ]);
            $this->assertSame($publicationId, $data->build($publication)->accession);
        }

        // Since 27 October 2026 the id labels a link to its repository permalink;
        // the label is the stored value (real Omeka renders a URI value as its URI),
        // and an unlabelled link names its id by the permalink.
        $linked = new ItemRepresentation('A book', 15, null, [
            'dcterms:identifier' => [
                new ValueRepresentation(
                    'https://eref.uni-bayreuth.de/id/eprint/70514/',
                    null,
                    'https://eref.uni-bayreuth.de/id/eprint/70514/',
                    'eref-70514',
                ),
            ],
        ]);
        $this->assertSame('eref-70514', $data->build($linked)->accession);
        $unlabelled = new ItemRepresentation('A book', 15, null, [
            'dcterms:identifier' => [
                new ValueRepresentation('', null, 'https://epub.uni-bayreuth.de/id/eprint/5720/', ''),
            ],
        ]);
        $this->assertSame('epub-5720', $data->build($unlabelled)->accession);

        // Free text alone yields nothing, so the cite key falls back to item-<id>.
        $freeText = new ItemRepresentation('A record', 10, null, [
            'dcterms:identifier' => [self::literalValue('Some descriptive shelf mark')],
        ]);
        $this->assertSame(null, $data->build($freeText)->accession);
    }

    public function testABareDOIAndADoiPrefixBothNormalise(): void
    {
        $data = self::citationDataService();

        foreach (
            [
            '10.1234/abcd',
            'doi:10.1234/abcd',
            'https://doi.org/10.1234/abcd',
            'http://dx.doi.org/10.1234/abcd',
            ] as $stored
        ) {
            $item = new ItemRepresentation('A', 11, null, ['bibo:doi' => [self::literalValue($stored)]]);
            $this->assertSame('10.1234/abcd', $data->build($item)->doi, "failed for {$stored}");
        }

        // A URI-typed DOI carrying no label still yields the identifier.
        $uriItem = new ItemRepresentation('A', 11, null, [
            'bibo:doi' => [new ValueRepresentation('', null, 'https://doi.org/10.1234/abcd')],
        ]);
        $this->assertSame('10.1234/abcd', $data->build($uriItem)->doi);
    }

    public function testOneItemIsBuiltOncePerRequestWhicheverRepresentationAsks(): void
    {
        $data = self::citationDataService();
        // Two representations of the same stored item, as the meta tags and the
        // theme's panel each hold their own.
        $first = new ItemRepresentation('A book', 15, 'Book', [], null, 41980, null, '2026-10-01 12:00:00');
        $second = new ItemRepresentation('A book', 15, 'Book', [], null, 41980, null, '2026-10-01 12:00:00');
        $edited = new ItemRepresentation('A book, edited', 15, 'Book', [], null, 41980, null, '2026-10-02 09:00:00');

        $this->assertTrue($data->build($first, 'u') === $data->build($second, 'u'), 'memoised by id');
        $this->assertTrue($data->build($first, 'u') !== $data->build($first, 'other'), 'per URL');
        $this->assertSame('A book, edited', $data->build($edited, 'u')->title, 'a later change is a new record');
    }
}
