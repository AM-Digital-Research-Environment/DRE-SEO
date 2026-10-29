<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;

/**
 * Formats a {@see CitationRecord} as a Chicago (18th ed., notes–bibliography),
 * APA (7th ed.) or MLA (9th ed.) reference, returning escaped HTML (italics via
 * <em>, the locator as a link). Hand-rolled — no CSL processor dependency —
 * because the DRE's kinds are a small, known set and the module carries no
 * bundled vendor/.
 *
 * Bilingual EN/FR: connective words and month names come from internal maps, so
 * a citation rendered under a French locale reads in French ("Dans", "sous la
 * dir. de", "7 décembre 2018") without a CSL locale file. Unknown locales fall
 * back to English.
 *
 * Title treatment follows each style's rule:
 *   • part-of works (journal article, conference paper, chapter, blog post,
 *     podcast episode, a video on a platform) — title in quotes (Chicago/MLA)
 *     or plain (APA), with the container in italics;
 *   • standalone works (book, report, dataset, research item, a thesis in APA
 *     and MLA) — title in italics; Chicago quotes a thesis.
 *   • a quoted title's own double quotes become single ones, and the closing
 *     period is not doubled when the title already ends in punctuation.
 *
 * Entries — authority records, cited as pages of the database — carry no
 * creator: the record title, what kind of record it is, the database, its
 * publisher and the date the record last changed. That last date stands in for
 * the access date the styles ask of changing pages; an access date would bake
 * render-time state into otherwise cacheable output. Works carry no access date
 * either: every DRE record has a stable permalink and many a DOI.
 */
final class CitationFormatter
{
    public const STYLES = ['chicago', 'apa', 'mla'];

    /** @var array<string,array<string,string>> Connectives per locale. */
    private const STR = [
        'en' => [
            'and' => 'and', 'et_al' => 'et al.', 'in' => 'In', 'eds' => 'edited by',
            'no' => 'no.', 'vol' => 'vol.', 'pp' => 'pp.', 'p' => 'p.',
            'phd' => 'PhD diss.', 'masters' => 'Master’s thesis', 'bachelors' => 'Bachelor’s thesis',
            'thesis' => 'Thesis', 'video' => 'Video', 'platform_video' => '%s video', 'dataset' => 'Data set',
            'untitled' => 'Untitled', 'episode' => 'episode', 'apa_no' => 'No.',
            'host' => 'host', 'hosts' => 'hosts', 'apa_host' => 'Host', 'apa_hosts' => 'Hosts',
            'apa_doctoral' => 'Doctoral dissertation', 'apa_podcast' => 'Audio podcast episode',
            'apa_paper' => 'Paper presentation', 'paper_at' => 'Paper presented at',
            'mla_doctoral' => 'PhD dissertation', 'last_modified' => 'Last modified',
            'entry_person' => 'Person record', 'entry_place' => 'Place record',
            'entry_organization' => 'Organisation record', 'entry_project' => 'Project record',
            'entry_section' => 'Research section record', 'entry_authority' => 'Authority record',
            'entry_journal' => 'Journal record',
        ],
        'fr' => [
            'and' => 'et', 'et_al' => 'et al.', 'in' => 'Dans', 'eds' => 'sous la dir. de',
            'no' => 'n°', 'vol' => 'vol.', 'pp' => 'p.', 'p' => 'p.',
            'phd' => 'thèse de doctorat', 'masters' => 'mémoire de master', 'bachelors' => 'mémoire de licence',
            'thesis' => 'thèse', 'video' => 'Vidéo', 'platform_video' => 'Vidéo %s', 'dataset' => 'Jeu de données',
            'untitled' => 'Sans titre', 'episode' => 'épisode', 'apa_no' => 'n°',
            'host' => 'anim.', 'hosts' => 'anim.', 'apa_host' => 'anim.', 'apa_hosts' => 'anim.',
            'apa_doctoral' => 'Thèse de doctorat', 'apa_podcast' => 'Épisode de podcast audio',
            'apa_paper' => 'Communication', 'paper_at' => 'Communication présentée à',
            'mla_doctoral' => 'Thèse de doctorat', 'last_modified' => 'Dernière modification le',
            'entry_person' => 'Notice de personne', 'entry_place' => 'Notice de lieu',
            'entry_organization' => 'Notice d’organisation', 'entry_project' => 'Notice de projet',
            'entry_section' => 'Notice de section de recherche', 'entry_authority' => 'Notice d’autorité',
            'entry_journal' => 'Notice de revue',
        ],
    ];

    /** @var array<string,array<int,string>> */
    private const MONTHS = [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    /** MLA abbreviates the month names longer than four letters in a works-cited entry. */
    private const MLA_MONTHS_EN = [1 => 'Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

    public function format(CitationRecord $record, string $style, string $locale = 'en'): string
    {
        $style = in_array($style, self::STYLES, true) ? $style : 'chicago';
        $locale = isset(self::STR[$locale]) ? $locale : 'en';

        if ($record->isEntry()) {
            return match ($style) {
                'apa' => $this->apaEntry($record, $locale),
                'mla' => $this->mlaEntry($record, $locale),
                default => $this->chicagoEntry($record, $locale),
            };
        }

        return match ($style) {
            'apa' => $this->apa($record, $locale),
            'mla' => $this->mla($record, $locale),
            default => $this->chicago($record, $locale),
        };
    }

    // ─── Entries (authority records, cited as database pages) ────────────────

    /** “Aas, Norbert.” Person record. AMIRA. Publisher. Last modified June 9, 2026. URL. */
    private function chicagoEntry(CitationRecord $record, string $locale): string
    {
        $parts = [$this->quoted($this->entryTitle($record, $locale))];
        $parts[] = $this->terminate($this->esc($this->entryLabel($record, $locale)));
        $parts[] = $this->terminate($this->esc($record->archive));
        $parts[] = $this->terminate($this->esc($record->publisher));
        $updated = $this->dateText($record->updated, $locale, 'chicago');
        if ($updated !== '') {
            $parts[] = $this->terminate($this->esc($this->str($locale, 'last_modified')) . ' ' . $updated);
        }
        $parts[] = $this->linkSegment($record);

        return $this->join($parts);
    }

    /** Aas, Norbert [Person record]. (2026, June 9). In *AMIRA*. Publisher. URL */
    private function apaEntry(CitationRecord $record, string $locale): string
    {
        $date = $this->dateText($record->updated, $locale, 'apa');
        $parts = [
            $this->terminate($this->esc($this->entryTitle($record, $locale))
                . ' [' . $this->esc($this->entryLabel($record, $locale)) . ']'),
            '(' . ($date !== '' ? $date : 'n.d.') . ').',
        ];
        if ($record->archive !== null) {
            $parts[] = $this->terminate($this->str($locale, 'in') . ' ' . $this->italic($record->archive));
        }
        $parts[] = $this->terminate($this->esc($record->publisher));
        $parts[] = $this->linkSegment($record);

        return $this->join($parts);
    }

    /** “Aas, Norbert.” *AMIRA*, Publisher, 9 June 2026, URL. */
    private function mlaEntry(CitationRecord $record, string $locale): string
    {
        $segments = array_filter([
            $this->italic($record->archive),
            $this->esc($record->publisher),
            $this->dateText($record->updated, $locale, 'mla'),
        ], static fn (string $s): bool => $s !== '');

        return $this->join([
            $this->quoted($this->entryTitle($record, $locale)),
            $this->mlaClose(implode(', ', $segments), $record),
        ]);
    }

    private function entryTitle(CitationRecord $record, string $locale): string
    {
        return $record->title ?: $this->str($locale, 'untitled');
    }

    private function entryLabel(CitationRecord $record, string $locale): string
    {
        return $this->str($locale, 'entry_' . $record->kind->value);
    }

    // ─── Chicago (notes–bibliography, bibliography entry) ────────────────────

    private function chicago(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        // Creator slot: chapter → its own author only (the book's editors go in
        // the container as "edited by …"); everything else → authors, or the
        // editors as editors for an edited volume.
        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'chicago')
            : $this->creators($record, $locale, 'chicago');
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
        }

        $parts[] = $this->titleSegment($record, $locale, 'chicago');

        switch ($kind) {
            case CitationKind::Article:
                $seg = $this->italic($record->container);
                $vi = $this->volumeIssue($record, $locale);
                $pages = $this->pages($record);
                if ($vi !== '') {
                    // A journal: "Journal 42, no. 3 (2001): 185–209."
                    $seg = trim($seg . ' ' . $vi);
                    $year = $this->year($record);
                    if ($year !== null) {
                        $seg .= ' (' . $this->esc($year) . ')';
                    }
                    if ($pages !== '') {
                        $seg .= ': ' . $pages;
                    }
                } else {
                    // A newspaper, magazine or online journal without volume
                    // numbering is dated: "Frankfurter Rundschau, May 1, 2020."
                    $seg = $this->joinComma([$seg, $this->fullDate($record, $locale, 'chicago'), $pages]);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Chapter:
            case CitationKind::Conference:
                // A chapter's book and a conference paper's proceedings take the
                // same "In <container>, edited by …, pages. Publisher, Year."
                $container = $kind === CitationKind::Chapter ? $record->bookTitle : $record->container;
                if ($container === null && $kind === CitationKind::Conference && $record->event !== null) {
                    // A paper with no proceedings is cited as presented.
                    $parts[] = $this->terminate($this->presentedAt($record, $locale, 'chicago'));
                    break;
                }
                if ($container !== null) {
                    $seg = $this->str($locale, 'in') . ' ' . $this->italic($container);
                    $eds = $this->nameList($record->editors, $locale, 'chicago', false);
                    if ($eds !== '') {
                        $seg .= ', ' . $this->str($locale, 'eds') . ' ' . $eds;
                    }
                    if ($this->pages($record) !== '') {
                        $seg .= ', ' . $this->pages($record);
                    }
                    $parts[] = $this->terminate($seg);
                }
                $parts[] = $this->terminate($this->publisherYear($record));
                break;

            case CitationKind::Post:
            case CitationKind::Podcast:
                $parts[] = $this->terminate($this->joinComma([
                    $this->italic($record->container),
                    $this->episode($record, $locale),
                    $this->fullDate($record, $locale, 'chicago'),
                ], true));
                break;

            case CitationKind::Thesis:
                $parts[] = $this->terminate($this->joinComma([
                    $this->degree($record, $locale, 'chicago'),
                    $this->esc($record->publisher),
                    $this->esc($this->year($record)),
                ]));
                break;

            case CitationKind::Video:
                if ($record->platform !== null) {
                    // An online video is a short work on its platform:
                    // “Title.” YouTube video, July 8, 2022.
                    $parts[] = $this->terminate($this->joinComma([
                        $this->esc(sprintf($this->str($locale, 'platform_video'), $record->platform)),
                        $this->fullDate($record, $locale, 'chicago'),
                    ]));
                    break;
                }
                $parts[] = $this->terminate($this->esc($this->str($locale, 'video')));
                $parts[] = $this->terminate($this->publisherYear($record));
                break;

            case CitationKind::Item:
                // A research item is an archival object: date, then the
                // collection (its project) and the archive that holds it.
                $parts[] = $this->terminate($this->esc($this->year($record)));
                $parts[] = $this->terminate($this->esc($record->container));
                $parts[] = $this->terminate($this->esc($record->archive));
                break;

            default: // book, report, dataset
                $descriptor = $this->descriptor($record, $locale);
                if ($descriptor !== '') {
                    $parts[] = $this->terminate($this->esc($descriptor));
                }
                if ($this->hasSeries($record)) {
                    $parts[] = $this->terminate($this->series($record));
                    $parts[] = $this->terminate($this->joinComma([
                        $this->esc($record->publisher),
                        $this->esc($this->year($record)),
                    ]));
                } else {
                    $parts[] = $this->terminate($this->publisherYear($record));
                }
                break;
        }

        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── APA (7th edition, reference list entry) ─────────────────────────────

    private function apa(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        // Date in parentheses; periodicals, presentations and online media
        // carry the full date.
        $date = $this->apaWantsFullDate($record)
            ? $this->fullDate($record, $locale, 'apa')
            : $this->esc($this->year($record));
        $dateSeg = '(' . ($date !== '' ? $date : 'n.d.') . ').';

        // "Creator. (Date). Title." — with no creator the title takes the slot
        // and the date follows it ("Title. (Date)."), per APA. Chapter → its own
        // author; other kinds → authors, or the editors for an edited volume.
        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'apa')
            : $this->creators($record, $locale, 'apa');
        $title = $this->apaTitle($record, $locale);
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
            $parts[] = $dateSeg;
            $parts[] = $title;
        } else {
            $parts[] = $title;
            $parts[] = $dateSeg;
        }

        switch ($kind) {
            case CitationKind::Article:
                $seg = $this->italic($record->container);
                if ($this->esc($record->volume) !== '') {
                    $seg .= ', ' . $this->italic($record->volume);
                    if ($record->issue !== null) {
                        $seg .= '(' . $this->esc($record->issue) . ')';
                    }
                }
                if ($this->pages($record) !== '') {
                    $seg .= ', ' . $this->pages($record);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Chapter:
            case CitationKind::Conference:
                $container = $kind === CitationKind::Chapter ? $record->bookTitle : $record->container;
                if ($container === null) {
                    // A presentation names its event: "Speech Prosody 2022, Lisbon."
                    $parts[] = $this->terminate($this->esc($record->event));
                    if ($record->event === null) {
                        $parts[] = $this->terminate($this->esc($record->publisher));
                    }
                    break;
                }
                // In {editors} (Eds.), *Book / proceedings title* (pp. x–y). Publisher.
                $seg = $this->str($locale, 'in') . ' ';
                $eds = $this->nameList($record->editors, $locale, 'apa', false);
                if ($eds !== '') {
                    $seg .= $eds . ' ' . $this->editorRole(count($record->editors), 'apa', $locale) . ', ';
                }
                $seg .= $this->italic($container);
                if ($this->pages($record) !== '') {
                    $seg .= ' (' . $this->str($locale, 'pp') . ' ' . $this->pages($record) . ')';
                }
                $parts[] = $this->terminate($seg);
                $parts[] = $this->terminate($this->esc($record->publisher));
                break;

            case CitationKind::Podcast:
                // "In *Podcast name*." — the series is the container.
                if ($record->container !== null) {
                    $parts[] = $this->terminate($this->str($locale, 'in') . ' ' . $this->italic($record->container));
                }
                $parts[] = $this->terminate($this->esc($record->publisher));
                break;

            case CitationKind::Post:
                $parts[] = $this->terminate($this->italic($record->container));
                break;

            case CitationKind::Thesis:
                // The institution sits inside the bracket after the title.
                break;

            case CitationKind::Video:
                $parts[] = $this->terminate($this->esc($record->platform ?? $record->publisher));
                break;

            case CitationKind::Item:
                // Archival: "Collection, Archive."
                $parts[] = $this->terminate($this->joinComma([
                    $this->esc($record->container),
                    $this->esc($record->archive),
                ]));
                break;

            default: // book, report, dataset
                $parts[] = $this->terminate($this->esc($record->publisher));
                break;
        }

        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    /**
     * APA's title element: the title (italic for a standalone work), a
     * podcast's episode number and the bracketed form descriptor, which
     * attaches to the title rather than standing as its own sentence —
     * "*Survey responses* [Data set]." and "*Islam politique* [Doctoral
     * dissertation, Université Laval]."
     */
    private function apaTitle(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $title = $record->title ?: $this->str($locale, 'untitled');
        // APA: only standalone works are italic; parts stay plain. A thesis
        // and a video are standalone in APA, wherever they were published, and
        // so is a paper that was presented but never printed in proceedings.
        $standalone = !$kind->isPartOfWork()
            || ($kind === CitationKind::Conference && $record->container === null);
        $seg = $standalone ? $this->italic($title) : $this->esc($title);

        if ($kind === CitationKind::Podcast && $record->number !== null) {
            $seg .= ' (' . $this->str($locale, 'apa_no') . ' ' . $this->esc($record->number) . ')';
        }

        $descriptor = match (true) {
            $kind === CitationKind::Thesis => $this->joinComma([
                $this->esc($this->ucfirst($this->degree($record, $locale, 'apa'))),
                $this->esc($record->publisher),
            ]),
            $kind === CitationKind::Podcast => $this->esc($this->str($locale, 'apa_podcast')),
            $kind === CitationKind::Conference && $record->container === null && $record->event !== null
                => $this->esc($this->str($locale, 'apa_paper')),
            default => $this->esc($this->descriptor($record, $locale)),
        };
        if ($descriptor !== '') {
            $seg .= ' [' . $descriptor . ']';
        }

        return $this->terminate($seg);
    }

    /**
     * Periodicals without volume numbering (newspapers, magazines, blogs),
     * podcast episodes, online videos and conference presentations take the
     * full date in APA; everything else the year.
     */
    private function apaWantsFullDate(CitationRecord $record): bool
    {
        return match ($record->kind) {
            CitationKind::Post, CitationKind::Podcast, CitationKind::Video => true,
            CitationKind::Article => $record->volume === null && $record->issue === null,
            CitationKind::Conference => $record->container === null,
            default => false,
        };
    }

    // ─── MLA (9th edition, works-cited entry) ────────────────────────────────

    /**
     * MLA strings the container elements together with commas and ends the
     * last container with the location — the DOI or URL — before the closing
     * period: "*Journal*, vol. 42, no. 3, 2001, pp. 185–209, https://doi.org/…".
     */
    private function mla(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'mla')
            : $this->creators($record, $locale, 'mla');
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
        }

        $parts[] = $this->titleSegment($record, $locale, 'mla');
        $date = $this->fullDate($record, $locale, 'mla');
        $pp = $this->pages($record) !== '' ? $this->str($locale, 'pp') . ' ' . $this->pages($record) : '';

        switch ($kind) {
            case CitationKind::Article:
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->italic($record->container),
                    $record->volume !== null ? $this->str($locale, 'vol') . ' ' . $this->esc($record->volume) : '',
                    $record->issue !== null ? $this->str($locale, 'no') . ' ' . $this->esc($record->issue) : '',
                    $date,
                    $pp,
                ]), $record);
                break;

            case CitationKind::Chapter:
            case CitationKind::Conference:
                $container = $kind === CitationKind::Chapter ? $record->bookTitle : $record->container;
                if ($container === null && $kind === CitationKind::Conference && $record->event !== null) {
                    $parts[] = $this->mlaClose($this->presentedAt($record, $locale, 'mla'), $record);
                    break;
                }
                $eds = $this->nameList($record->editors, $locale, 'mla', false);
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->italic($container),
                    $eds !== '' ? $this->str($locale, 'eds') . ' ' . $eds : '',
                    $this->publisherYear($record),
                    $pp,
                ]), $record);
                break;

            case CitationKind::Post:
            case CitationKind::Podcast:
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->italic($record->container),
                    $this->episode($record, $locale),
                    $date,
                ], true), $record);
                break;

            case CitationKind::Thesis:
                // "*Title*. 2018. Université Laval, PhD dissertation." The
                // degree names the work, so a thesis is not read as a book.
                $parts[] = $this->terminate($this->esc($this->year($record)));
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->esc($record->publisher),
                    $this->esc($this->degree($record, $locale, 'mla')),
                ], true), $record);
                break;

            case CitationKind::Video:
                if ($record->platform !== null) {
                    $parts[] = $this->mlaClose($this->joinComma([$this->italic($record->platform), $date]), $record);
                    break;
                }
                $parts[] = $this->terminate($this->esc($this->str($locale, 'video')));
                $parts[] = $this->mlaClose($this->publisherYear($record), $record);
                break;

            case CitationKind::Item:
                $parts[] = $this->terminate($this->esc($this->year($record)));
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->esc($record->container),
                    $this->esc($record->archive),
                ]), $record);
                break;

            default: // book, report, dataset
                $descriptor = $this->descriptor($record, $locale);
                if ($descriptor !== '') {
                    $parts[] = $this->terminate($this->esc($descriptor));
                }
                $parts[] = $this->mlaClose($this->joinComma([
                    $this->esc($record->publisher ?? ($this->hasSeries($record) ? null : $record->container)),
                    $this->esc($this->year($record)),
                ]), $record);
                break;
        }

        return $this->join($parts);
    }

    /**
     * Close MLA's last container with the location: "segment, URL." — or the
     * segment alone, or the URL alone, as a sentence.
     */
    private function mlaClose(string $segment, CitationRecord $record): string
    {
        $link = $this->link($record);
        return $this->terminate($this->joinComma([$segment, $link]));
    }

    // ─── Title ───────────────────────────────────────────────────────────────

    private function titleSegment(CitationRecord $record, string $locale, string $style): string
    {
        $title = $record->title ?: $this->str($locale, 'untitled');
        $kind = $record->kind;

        // Chicago / MLA: parts, and a video on a platform, go in quotation
        // marks; Chicago also quotes a thesis, which MLA italicises. Every
        // other standalone work is italic.
        $quoted = $kind->isPartOfWork()
            || ($kind === CitationKind::Video && $record->platform !== null)
            || ($kind === CitationKind::Thesis && $style === 'chicago');
        if ($quoted) {
            return $this->quoted($title);
        }
        return $this->terminate($this->italic($title));
    }

    /**
     * A title in double quotation marks with the closing period inside — and
     * the title's own double quotes turned to single ones, so “Parvati
     * Raghuram: ‘Four Moves …’” does not nest a pair inside a pair. A title
     * that already ends in a period, question mark or exclamation mark gets no
     * second one; a title ending in a quotation takes the period inside it.
     */
    private function quoted(string $title): string
    {
        $text = preg_replace(['/"([^"]*)"/u', '/“([^”]*)”/u'], ['‘$1’', '‘$1’'], $title) ?? $title;
        if (!preg_match('/[.!?][’\'"”]*$/u', $text)) {
            $text = preg_match('/[’\']$/u', $text)
                ? mb_substr($text, 0, -1) . '.' . mb_substr($text, -1)
                : $text . '.';
        }
        return '“' . $this->esc($text) . '”';
    }

    /**
     * The form descriptor for a non-print work — "Data set", "Video". APA
     * brackets it after the title; Chicago and MLA set it as its own sentence.
     */
    private function descriptor(CitationRecord $record, string $locale): string
    {
        return match ($record->kind) {
            CitationKind::Dataset => $this->ucfirst($this->str($locale, 'dataset')),
            CitationKind::Video => $this->ucfirst($this->str($locale, 'video')),
            default => '',
        };
    }

    /**
     * A thesis's degree line in the style's own words — Chicago "PhD diss.",
     * APA "Doctoral dissertation", MLA "PhD dissertation"; "Master’s thesis"
     * in all three. A record that names no degree (a habilitation) is a plain
     * "Thesis" rather than a doctorate the record cannot vouch for.
     */
    private function degree(CitationRecord $record, string $locale, string $style): string
    {
        return $this->str($locale, match ($record->degree) {
            'doctoral' => match ($style) {
                'apa' => 'apa_doctoral',
                'mla' => 'mla_doctoral',
                default => 'phd',
            },
            'masters' => 'masters',
            'bachelors' => 'bachelors',
            default => 'thesis',
        });
    }

    /** "Paper presented at Speech Prosody 2022, …" (Chicago) or the bare event (MLA), dated unless the event names its year. */
    private function presentedAt(CitationRecord $record, string $locale, string $style): string
    {
        $event = (string) $record->event;
        $seg = $style === 'chicago'
            ? $this->esc($this->str($locale, 'paper_at') . ' ' . $event)
            : $this->esc($event);
        $year = $this->year($record);
        if ($year !== null && !str_contains($event, $year)) {
            $seg .= ', ' . $this->fullDate($record, $locale, $style);
        }
        return $seg;
    }

    /** "episode 4" for a numbered podcast episode. */
    private function episode(CitationRecord $record, string $locale): string
    {
        if ($record->kind !== CitationKind::Podcast || $record->number === null) {
            return '';
        }
        return $this->esc($this->str($locale, 'episode') . ' ' . $record->number);
    }

    // ─── Creators (authors / editors) ────────────────────────────────────────

    /**
     * The creator slot: the authors (with their role when it is not authorship
     * — a podcast host), or — for an edited work with no authors (an edited
     * volume) — the editors followed by an "ed(s)." role label.
     */
    private function creators(CitationRecord $record, string $locale, string $style): string
    {
        $authors = $this->nameList($record->authors, $locale, $style);
        if ($authors !== '') {
            if ($record->authorRole === 'host') {
                $plural = count($record->authors) > 1;
                return $style === 'apa'
                    ? $authors . ' (' . $this->str($locale, $plural ? 'apa_hosts' : 'apa_host') . ')'
                    : $authors . ', ' . $this->str($locale, $plural ? 'hosts' : 'host');
            }
            return $authors;
        }
        $editors = $record->editors;
        $names = $this->nameList($editors, $locale, $style);
        if ($names === '') {
            return '';
        }
        // Chicago/MLA: "Names, eds." · APA: "Names (Eds.)".
        return $names . ($style === 'apa' ? ' ' : ', ') . $this->editorRole(count($editors), $style, $locale);
    }

    /**
     * Format a list of people. The first name is inverted (Family, Given /
     * Family, I.) unless $invertFirst is false (e.g. a chapter's "edited by …");
     * subsequent names are natural order for Chicago/MLA. APA inverts to initials
     * for the reference-list slot ($invertFirst true) and puts the initials first
     * for a non-inverted list ("In J.-L. Triaud & D. Robinson (Eds.)").
     *
     * @param Creator[] $people
     */
    private function nameList(array $people, string $locale, string $style, bool $invertFirst = true): string
    {
        if (!$people) {
            return '';
        }
        $n = count($people);
        $first = $this->name($people[0], $style, $invertFirst);
        if ($n === 1) {
            return $first;
        }

        if ($style === 'mla' && $n >= 3) {
            return $first . ', ' . $this->str($locale, 'et_al');
        }

        // Subsequent names: natural order for Chicago/MLA; APA follows the list's
        // inversion (inverted for creator lists, initials-first for "edited by").
        $restInverted = $style === 'apa' ? $invertFirst : false;
        $rest = [];
        for ($i = 1; $i < $n; $i++) {
            $rest[] = $this->name($people[$i], $style, $restInverted);
        }

        $sep = $style === 'apa' ? '&' : $this->str($locale, 'and');
        if ($n === 2) {
            // A comma precedes the conjunction only when the first name is
            // inverted ("Triaud, Jean-Louis, and …"); a natural-order pair
            // ("edited by A and B") takes none.
            $glue = $invertFirst ? ', ' . $sep . ' ' : ' ' . $sep . ' ';
            return $first . $glue . $rest[0];
        }
        // 3+ (Chicago/APA list all): "A, B, and/& C"
        $last = array_pop($rest);
        return $first . ', ' . implode(', ', $rest) . ', ' . $sep . ' ' . $last;
    }

    /** Role label after editor names in the creator slot ("eds." / "(Eds.)" / "editors"). */
    private function editorRole(int $count, string $style, string $locale): string
    {
        if ($locale === 'fr') {
            // French uses "dir." (sous la direction de), invariant in number.
            return $style === 'apa' ? '(dir.)' : 'dir.';
        }
        $plural = $count > 1;
        return match ($style) {
            'apa' => $plural ? '(Eds.)' : '(Ed.)',
            'mla' => $plural ? 'editors' : 'editor',
            default => $plural ? 'eds.' : 'ed.', // chicago
        };
    }

    private function name(Creator $person, string $style, bool $inverted): string
    {
        if ($person->isSingleField()) {
            return $this->esc($person->literal);
        }
        $family = (string) $person->family;
        $given = (string) ($person->given ?? '');

        if ($style === 'apa') {
            $initials = $this->initials($given);
            if ($initials === '') {
                return $this->esc($family);
            }
            // "Family, Initials" for the reference-list creator slot; initials
            // first ("J.-L. Triaud") for a non-inverted list (chapter editors).
            return $inverted
                ? $this->esc($family) . ', ' . $this->esc($initials)
                : $this->esc($initials) . ' ' . $this->esc($family);
        }
        if ($given === '') {
            return $this->esc($family);
        }
        return $inverted
            ? $this->esc($family) . ', ' . $this->esc($given)
            : $this->esc($given) . ' ' . $this->esc($family);
    }

    /** "Frédérick" → "F.", "Jean-Paul" → "J.-P.", "Muriel Anne" → "M. A." */
    private function initials(string $given): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($given)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $bits = array_map(
                fn (string $p) => $p !== '' ? mb_strtoupper(mb_substr($p, 0, 1)) . '.' : '',
                explode('-', $word)
            );
            $out[] = implode('-', array_filter($bits));
        }
        return implode(' ', $out);
    }

    // ─── Shared segment builders ─────────────────────────────────────────────

    private function volumeIssue(CitationRecord $record, string $locale): string
    {
        $out = '';
        if ($record->volume !== null) {
            $out .= $this->esc($record->volume);
        }
        if ($record->issue !== null) {
            $out .= ($out !== '' ? ', ' : '') . $this->str($locale, 'no') . ' ' . $this->esc($record->issue);
        }
        return $out;
    }

    /** The page range for display, en-dashed: "185–209". */
    private function pages(CitationRecord $record): string
    {
        $range = $record->pageRange();
        return $range === null ? '' : $this->esc(str_replace('-', '–', $range));
    }

    /**
     * A book or report in a numbered series names the series after its title
     * ("Bayreuther Kontaktstudium Geographie 13"): its isPartOf is that series,
     * not a publisher, and only Chicago prints it.
     */
    private function hasSeries(CitationRecord $record): bool
    {
        return in_array($record->kind, [CitationKind::Book, CitationKind::Report], true)
            && $record->container !== null;
    }

    private function series(CitationRecord $record): string
    {
        return trim($this->esc($record->container) . ' ' . $this->esc($record->volume));
    }

    /** "Publisher, Year" (Chicago/MLA book-like). */
    private function publisherYear(CitationRecord $record): string
    {
        return $this->joinComma([
            $this->esc($record->publisher ?? $record->container),
            $this->esc($this->year($record)),
        ]);
    }

    private function linkSegment(CitationRecord $record): string
    {
        $link = $this->link($record);
        return $link !== '' ? $link . '.' : '';
    }

    private function link(CitationRecord $record): string
    {
        $href = $record->link();
        if ($href === null) {
            return '';
        }
        $hrefEsc = $this->esc($href);
        return '<a href="' . $hrefEsc . '">' . $hrefEsc . '</a>';
    }

    // ─── Dates ───────────────────────────────────────────────────────────────

    private function year(CitationRecord $record): ?string
    {
        return $record->issued->yearOrLiteral();
    }

    private function fullDate(CitationRecord $record, string $locale, string $style): string
    {
        return $this->dateText($record->issued, $locale, $style);
    }

    /**
     * Full date in the style's order:
     *   Chicago  → "December 7, 2018" / "7 décembre 2018"
     *   APA      → "2018, December 7"
     *   MLA      → "7 Dec. 2018"
     * Falls back to the year (or the raw literal) when month/day are absent.
     */
    private function dateText(\DRESeo\Service\Citation\IssuedDate $date, string $locale, string $style): string
    {
        $y = $date->year;
        $m = $date->month;
        $d = $date->day;
        if ($y === null) {
            return $date->literal !== null ? $this->esc($date->literal) : '';
        }
        if (!$m) {
            return $this->esc((string) $y);
        }
        $month = $style === 'mla' && $locale === 'en'
            ? self::MLA_MONTHS_EN[$m] ?? (string) $m
            : self::MONTHS[$locale][$m] ?? self::MONTHS['en'][$m] ?? (string) $m;

        return match ($style) {
            'apa' => $this->esc($y . ', ' . $month . ($d ? ' ' . $d : '')),
            'mla' => $this->esc(($d ? $d . ' ' : '') . $month . ' ' . $y),
            default => $locale === 'fr'
                ? $this->esc(($d ? $d . ' ' : '') . $month . ' ' . $y)          // 7 décembre 2018
                : $this->esc($month . ($d ? ' ' . $d . ',' : '') . ' ' . $y),   // December 7, 2018
        };
    }

    // ─── Primitives ──────────────────────────────────────────────────────────

    private function str(string $locale, string $key): string
    {
        return self::STR[$locale][$key] ?? self::STR['en'][$key] ?? $key;
    }

    private function esc(?string $text): string
    {
        return $text === null ? '' : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /** Escape $text and wrap non-empty in <em>. */
    private function italic(?string $text): string
    {
        $esc = $this->esc($text);
        return $esc !== '' ? '<em>' . $esc . '</em>' : '';
    }

    /** Append a period unless the segment already ends in terminal punctuation. */
    private function terminate(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return '';
        }
        return preg_match('/[.!?]$/u', strip_tags($segment)) ? $segment : $segment . '.';
    }

    private function ucfirst(string $text): string
    {
        if ($text === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Comma-join the non-empty segments. $capitalise upper-cases the first
     * letter when the leading segment is missing and a lower-case one ("episode
     * 4", "thèse de doctorat") now opens the sentence.
     *
     * @param array<int,string|null> $segments
     */
    private function joinComma(array $segments, bool $capitalise = false): string
    {
        $kept = array_values(array_filter(
            array_map(static fn (?string $s): string => trim((string) $s), $segments),
            static fn (string $s): bool => $s !== ''
        ));
        $out = implode(', ', $kept);
        if ($capitalise && $out !== '' && !str_starts_with($out, '<')) {
            $out = $this->ucfirst($out);
        }
        return $out;
    }

    /**
     * Join non-empty segments with single spaces.
     *
     * @param array<string> $parts
     */
    private function join(array $parts): string
    {
        return implode(' ', array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
}
