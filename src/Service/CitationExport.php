<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\CitationRecord;
use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Citation\IssuedDate;

/**
 * Serialises a {@see CitationRecord} to the machine-readable citation formats
 * offered on the record page: **BibTeX**, **RIS** and **CSL-JSON**.
 *
 * All three are dependency-free string builders (the module carries no vendor/),
 * and all emit UTF-8 — BibTeX targets biber/biblatex, which reads UTF-8 directly.
 * The RIS tags follow the ones Zotero's own RIS translator reads back (T2 is a
 * book's series and a web page's site, M3 a thesis or website type, DB/AN the
 * archive and accession number, M1 a podcast's episode number).
 *
 * An entry (an authority record) is exported as a web page of the database:
 * BibTeX @online, RIS ELEC, CSL `webpage`, dated by when the record last changed.
 */
final class CitationExport
{
    /** Format id => [extension, MIME type]. */
    public const FORMATS = [
        'bibtex'  => ['bib', 'application/x-bibtex; charset=utf-8'],
        'ris'     => ['ris', 'application/x-research-info-systems; charset=utf-8'],
        'csljson' => ['json', 'application/vnd.citationstyles.csl+json; charset=utf-8'],
    ];

    /**
     * BibTeX entry type and `type` label per thesis degree. Classic BibTeX has
     * only @phdthesis and @mastersthesis; the `type` field relabels the rest,
     * and a thesis that names no degree (a habilitation) prints as a plain
     * "Thesis" rather than as a PhD.
     */
    private const THESIS_ENTRIES = [
        'doctoral'  => ['phdthesis', null],
        'masters'   => ['mastersthesis', null],
        'bachelors' => ['mastersthesis', "Bachelor's thesis"],
    ];

    /** The thesis type exported to RIS M3 and CSL `genre`. */
    private const THESIS_GENRES = [
        'doctoral'  => 'Doctoral dissertation',
        'masters'   => "Master's thesis",
        'bachelors' => "Bachelor's thesis",
    ];

    public function serialize(CitationRecord $record, string $format): ?string
    {
        return match ($format) {
            'bibtex'  => $this->bibtex($record),
            'ris'     => $this->ris($record),
            'csljson' => $this->cslJson($record),
            default   => null,
        };
    }

    /** Download filename stem — the DRE id if present, else item-<id>. */
    public function filename(CitationRecord $record, string $format): string
    {
        [$ext] = self::FORMATS[$format] ?? ['txt', ''];
        $stem = $record->accession !== null
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', $record->accession)
            : 'item-' . $record->id;
        return trim((string) $stem, '-') . '.' . $ext;
    }

    // ─── Shared ──────────────────────────────────────────────────────────────

    /** The date an export carries: an entry's last change, a work's publication. */
    private function date(CitationRecord $record): IssuedDate
    {
        return $record->isEntry() ? $record->updated : $record->issued;
    }

    /** CSL `genre` / RIS M3: an entry's or research item's form, a thesis's degree. */
    private function genre(CitationRecord $record): ?string
    {
        if ($record->kind === CitationKind::Thesis) {
            return self::THESIS_GENRES[$record->degree ?? ''] ?? 'Thesis';
        }
        return $record->genre;
    }

    /** A book's or report's container is its series, not a containing work. */
    private function isSeries(CitationRecord $record): bool
    {
        return in_array($record->kind, [CitationKind::Book, CitationKind::Report], true);
    }

    // ─── BibTeX ──────────────────────────────────────────────────────────────

    private function bibtex(CitationRecord $record): string
    {
        $kind = $record->kind;
        $type = $kind->bibtexType();
        $key = $this->citeKey($record);

        $fields = [];
        $authors = $this->nameListBibtex($record->authors);
        if ($authors !== '') {
            $fields['author'] = $authors;
        }
        $editors = $this->nameListBibtex($record->editors);
        if ($editors !== '') {
            $fields['editor'] = $editors;
        }
        if ($record->title !== null) {
            // Wrap in an extra pair of braces to protect title-case.
            $fields['title'] = '{' . $this->bibtexEscape($record->title) . '}';
        }

        // Container routes to journal / booktitle / publisher-adjacent fields.
        if ($record->isEntry()) {
            // biblatex @online: the database is the organisation that hosts
            // the page, the record type a note.
            $this->addField($fields, 'organization', $record->archive);
            $this->addField($fields, 'publisher', $record->publisher);
            $this->addField($fields, 'note', $record->genre);
        } else {
            switch ($kind) {
                case CitationKind::Article:
                    $this->addField($fields, 'journal', $record->container);
                    $this->addField($fields, 'publisher', $record->publisher);
                    break;
                case CitationKind::Chapter:
                    $this->addField($fields, 'booktitle', $record->bookTitle);
                    $this->addField($fields, 'publisher', $record->publisher);
                    break;
                case CitationKind::Conference:
                    $this->addField($fields, 'booktitle', $record->container);
                    $this->addField($fields, 'eventtitle', $record->event);
                    $this->addField($fields, 'publisher', $record->publisher);
                    break;
                case CitationKind::Thesis:
                    [$type, $label] = self::THESIS_ENTRIES[$record->degree ?? ''] ?? ['phdthesis', 'Thesis'];
                    $this->addField($fields, 'school', $record->publisher ?? $record->container);
                    $this->addField($fields, 'type', $label);
                    break;
                case CitationKind::Report:
                    $this->addField($fields, 'institution', $record->publisher);
                    $this->addField($fields, 'series', $record->container);
                    break;
                case CitationKind::Book:
                    $this->addField($fields, 'publisher', $record->publisher);
                    $this->addField($fields, 'series', $record->container);
                    break;
                case CitationKind::Dataset:
                    $this->addField($fields, 'publisher', $record->publisher);
                    break;
                default: // post, podcast, video, item
                    $this->addField($fields, 'howpublished', $record->container ?? $record->platform ?? $record->publisher);
                    break;
            }
        }
        $this->addField($fields, 'address', $record->place);

        $date = $this->date($record);
        if ($date->hasYear()) {
            $fields['year'] = (string) $date->year;
            // biblatex reads the full date; classic BibTeX ignores the field.
            if ($date->month !== null) {
                $fields['date'] = $this->isoDate($date);
            }
        }
        $this->addField($fields, 'volume', $record->volume);
        $this->addField($fields, 'number', $record->issue ?? $record->number);
        $pages = $record->pageRange();
        if ($pages !== null) {
            $fields['pages'] = str_replace('-', '--', $pages);
        }
        $this->addField($fields, 'issn', $record->issn);
        $this->addField($fields, 'isbn', $record->isbn);
        // DOI/URL are verbatim fields — do NOT LaTeX-escape them; an underscore
        // in a handle or slug must reach biber literally.
        if ($record->doi !== null) {
            $fields['doi'] = $record->doi;
        }
        if ($record->url !== null) {
            $fields['url'] = $record->url;
        }
        $this->addField($fields, 'language', $record->language);
        if ($record->keywords !== []) {
            $fields['keywords'] = $this->bibtexEscape(implode(', ', $record->keywords));
        }
        if ($record->abstract !== null) {
            $fields['abstract'] = $this->bibtexEscape($record->abstract);
        }

        $lines = ["@{$type}{{$key},"];
        $width = 0;
        foreach (array_keys($fields) as $name) {
            $width = max($width, strlen($name));
        }
        foreach ($fields as $name => $value) {
            $lines[] = sprintf('  %-' . $width . 's = {%s},', $name, $value);
        }
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    private function citeKey(CitationRecord $record): string
    {
        $base = $record->accession ?? ('item-' . $record->id);
        return preg_replace('/[^A-Za-z0-9:_-]+/', '', $base) ?: 'dre';
    }

    /**
     * BibTeX author/editor list, " and "-joined; institutions kept in braces.
     *
     * @param Creator[] $people
     */
    private function nameListBibtex(array $people): string
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->isSingleField()) {
                $out[] = '{' . $this->bibtexEscape($person->literal) . '}';
            } elseif ($person->given !== null) {
                $out[] = $this->bibtexEscape((string) $person->family)
                    . ', ' . $this->bibtexEscape($person->given);
            } else {
                $out[] = $this->bibtexEscape((string) $person->family);
            }
        }
        return implode(' and ', $out);
    }

    private function bibtexEscape(string $text): string
    {
        // Escape the LaTeX specials that would otherwise break a value. Braces
        // are left intact (used deliberately around titles / corporate names).
        return str_replace(
            ['\\', '&', '%', '$', '#', '_', '~', '^'],
            ['\\textbackslash{}', '\\&', '\\%', '\\$', '\\#', '\\_', '\\textasciitilde{}', '\\textasciicircum{}'],
            $text
        );
    }

    // ─── RIS ─────────────────────────────────────────────────────────────────

    private function ris(CitationRecord $record): string
    {
        $eol = "\r\n";
        $lines = [];
        $lines[] = $this->risLine('TY', $record->kind->risType());

        foreach ($record->authors as $person) {
            $lines[] = $this->risLine('AU', $this->nameRis($person));
        }
        foreach ($record->editors as $person) {
            $lines[] = $this->risLine('ED', $this->nameRis($person));
        }
        $lines[] = $this->risLine('TI', $record->title);

        // T2 is the secondary title Zotero reads by type: a journal, a book's
        // or report's series, a chapter's book, a web page's site.
        $lines[] = $this->risLine('T2', $record->isEntry()
            ? $record->archive
            : ($record->container ?? $record->bookTitle ?? $record->event));
        $lines[] = $this->risLine('PB', $record->publisher ?? ($record->kind === CitationKind::Video ? $record->platform : null));
        $lines[] = $this->risLine('CY', $record->place);

        $date = $this->date($record);
        if ($date->hasYear()) {
            $lines[] = $this->risLine('PY', (string) $date->year);
            $lines[] = $this->risLine('DA', $this->risDate($date));
        }
        $lines[] = $this->risLine('VL', $record->volume);
        $lines[] = $this->risLine('IS', $record->issue);
        $lines[] = $this->risLine('M1', $record->number);
        $lines[] = $this->risLine('M3', $this->genre($record));
        $lines[] = $this->risLine('SP', $record->pageFirst);
        $lines[] = $this->risLine('EP', $record->pageLast);
        // SN carries whichever standard number the kind has (RIS has one slot).
        $lines[] = $this->risLine('SN', $record->issn ?? $record->isbn);
        $lines[] = $this->risLine('DO', $record->doi);
        $lines[] = $this->risLine('UR', $record->url);
        // A research item or entry is held in the archive under its accession id.
        if ($record->archive !== null) {
            $lines[] = $this->risLine('DB', $record->archive);
            $lines[] = $this->risLine('AN', $record->accession);
        }
        $lines[] = $this->risLine('LA', $record->language);
        foreach ($record->keywords as $keyword) {
            $lines[] = $this->risLine('KW', $keyword);
        }
        if ($record->abstract !== null) {
            $lines[] = $this->risLine('AB', $record->abstract);
        }
        $lines[] = 'ER  - ';

        return implode($eol, array_filter($lines, static fn ($l) => $l !== null)) . $eol;
    }

    private function risLine(string $tag, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // RIS is line-oriented; collapse any embedded newlines in the value.
        $value = preg_replace('/\s*\R\s*/u', ' ', $value) ?? $value;
        return sprintf('%-2s  - %s', $tag, $value);
    }

    private function nameRis(Creator $person): string
    {
        if ($person->isSingleField()) {
            return $person->literal;
        }
        return $person->given !== null
            ? $person->family . ', ' . $person->given
            : (string) $person->family;
    }

    private function risDate(IssuedDate $issued): ?string
    {
        if (!$issued->hasYear()) {
            return null;
        }
        // RIS DA is YYYY/MM/DD/ (trailing parts optional, slashes kept).
        $out = sprintf('%04d', $issued->year);
        if ($issued->month !== null) {
            $out .= sprintf('/%02d', $issued->month);
            if ($issued->day !== null) {
                $out .= sprintf('/%02d', $issued->day);
            }
        }
        return $out;
    }

    private function isoDate(IssuedDate $date): string
    {
        $out = sprintf('%04d', $date->year);
        if ($date->month !== null) {
            $out .= sprintf('-%02d', $date->month);
            if ($date->day !== null) {
                $out .= sprintf('-%02d', $date->day);
            }
        }
        return $out;
    }

    // ─── CSL-JSON ────────────────────────────────────────────────────────────

    private function cslJson(CitationRecord $record): string
    {
        $item = [
            'id'   => $record->accession ?? ('dre-' . $record->id),
            'type' => $record->cslType(),
        ];
        if ($record->title !== null) {
            $item['title'] = $record->title;
        }
        $authors = $this->nameListCsl($record->authors);
        if ($authors) {
            $item['author'] = $authors;
        }
        $editors = $this->nameListCsl($record->editors);
        if ($editors) {
            $item['editor'] = $editors;
        }

        if ($record->isEntry()) {
            // A web page's container is the site it is on.
            $container = $record->archive;
        } elseif ($this->isSeries($record)) {
            $container = null;
            if ($record->container !== null) {
                $item['collection-title'] = $record->container;
            }
            if ($record->volume !== null) {
                $item['collection-number'] = $record->volume;
            }
        } else {
            $container = $record->container ?? $record->bookTitle;
        }
        if ($container !== null) {
            $item['container-title'] = $container;
        }
        $optional = [
            'publisher'        => $record->publisher ?? ($record->kind === CitationKind::Video ? $record->platform : null),
            'publisher-place'  => $record->place,
            'event-title'      => $record->event,
            'volume'           => $this->isSeries($record) ? null : $record->volume,
            'issue'            => $record->issue,
            'number'           => $record->number,
            'page'             => $record->pageRange(),
            'genre'            => $this->genre($record),
            'archive'          => $record->archive,
            'archive_location' => $record->archive !== null ? $record->accession : null,
        ];
        foreach ($optional as $field => $value) {
            if ($value !== null && $value !== '') {
                $item[$field] = $value;
            }
        }
        $dateParts = $this->cslDateParts($this->date($record));
        if ($dateParts) {
            $item['issued'] = ['date-parts' => [$dateParts]];
        }
        if ($record->issn !== null) {
            $item['ISSN'] = $record->issn;
        }
        if ($record->isbn !== null) {
            $item['ISBN'] = $record->isbn;
        }
        if ($record->doi !== null) {
            $item['DOI'] = $record->doi;
        }
        if ($record->url !== null) {
            $item['URL'] = $record->url;
        }
        if ($record->language !== null) {
            $item['language'] = $record->language;
        }
        if ($record->abstract !== null) {
            $item['abstract'] = $record->abstract;
        }
        if ($record->keywords !== []) {
            $item['keyword'] = implode(', ', $record->keywords);
        }

        // CSL-JSON is an array of item objects.
        return json_encode(
            [$item],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /**
     * @param Creator[] $people
     * @return array<int,array<string,string>>
     */
    private function nameListCsl(array $people): array
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->isSingleField()) {
                $out[] = ['literal' => $person->literal];
                continue;
            }
            $name = ['family' => (string) $person->family];
            if ($person->given !== null) {
                $name['given'] = $person->given;
            }
            $out[] = $name;
        }
        return $out;
    }

    /** @return array<int,int> date-parts [year(,month(,day))] or [] */
    private function cslDateParts(IssuedDate $issued): array
    {
        if (!$issued->hasYear()) {
            return [];
        }
        $parts = [(int) $issued->year];
        if ($issued->month !== null) {
            $parts[] = $issued->month;
            if ($issued->day !== null) {
                $parts[] = $issued->day;
            }
        }
        return $parts;
    }

    private function addField(array &$fields, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $fields[$name] = $this->bibtexEscape($value);
        }
    }
}
