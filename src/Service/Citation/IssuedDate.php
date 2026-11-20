<?php
declare(strict_types=1);

namespace DRESeo\Service\Citation;

/**
 * A publication date, as precise as the archive records it.
 *
 * DRE dates are NumericDataTypes timestamps stored as YYYY, YYYY-MM or
 * YYYY-MM-DD, and the precision is meaningful: a podcast episode cited in
 * Chicago wants "7 December 2018", a book wants "2018". Month and day are
 * therefore nullable rather than defaulted, and a value that parses as no date
 * at all is kept verbatim in $literal so a citation can still show something.
 *
 * Some records carry an interval instead (NumericDataTypes' `start/end`, the
 * span over which a research item was made). It is kept as the start date plus
 * {@see $until}, the end. A format that can state a range (the text styles,
 * CSL-JSON, biblatex) states it; one that holds a single date (Highwire, RIS,
 * Dublin Core, schema.org) takes {@see asSingleDate()}, which claims no more
 * precision than the two ends share, so 1950-01-01/1962-01-01 is "1950", never
 * 1 January 1950.
 *
 * This is the one parser and the one set of serialisers for a stored date:
 * every head tag, export and citation reads it, so they cannot disagree on
 * what a value means, and a month or day that cannot exist is never emitted.
 */
final class IssuedDate
{
    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
        public readonly ?int $day = null,
        public readonly ?string $literal = null,
        public readonly ?IssuedDate $until = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Parse a stored timestamp or interval. Anything with a four-digit year
     * yields its parts (a month or day that cannot exist is dropped); anything
     * else is preserved as a literal.
     */
    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::unknown();
        }
        // An interval: "start/end" as NumericDataTypes stores it, or a span of
        // two years typed as text ("1999-2001", "1999–2001").
        if (
            preg_match('~^(.+?)\s*/\s*(.+)$~u', $raw, $m)
            || preg_match('~^(\d{4})\s*[-–]\s*(\d{4})$~u', $raw, $m)
        ) {
            $start = self::point($m[1]);
            $end = self::point($m[2]);
            if ($start->year !== null && $end->year !== null) {
                $same = $start->year === $end->year && $start->month === $end->month && $start->day === $end->day;
                return new self($start->year, $start->month, $start->day, $raw, $same ? null : $end);
            }
        }
        $point = self::point($raw);
        return new self($point->year, $point->month, $point->day, $raw);
    }

    /** One date: its parts, validated, or no year at all. */
    private static function point(string $raw): self
    {
        if (!preg_match('/(?<!\d)(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?(?!\d)/', $raw, $m)) {
            return new self(null, null, null, trim($raw));
        }
        $year = (int) $m[1];
        $month = ($m[2] ?? '') !== '' ? (int) $m[2] : null;
        $day = ($m[3] ?? '') !== '' ? (int) $m[3] : null;
        if ($month !== null && ($month < 1 || $month > 12)) {
            // "1999-20" is no month; keep the year it does name.
            $month = null;
            $day = null;
        }
        if ($day !== null && ($month === null || !checkdate($month, $day, $year))) {
            $day = null;
        }
        return new self($year, $month, $day);
    }

    /** Whether the value spans two different dates. */
    public function isInterval(): bool
    {
        return $this->until !== null;
    }

    /**
     * The value as one date, for a format that holds no range: an interval
     * keeps only the parts its two ends share (the year, or none).
     */
    public function asSingleDate(): self
    {
        if ($this->until === null) {
            return $this;
        }
        if ($this->year !== $this->until->year) {
            return new self($this->year, null, null, $this->literal);
        }
        $month = $this->month === $this->until->month ? $this->month : null;
        $day = $month !== null && $this->day === $this->until->day ? $this->day : null;
        return new self($this->year, $month, $day, $this->literal);
    }

    /**
     * The year as a string ("1950–1962" for an interval across years), falling
     * back to the raw literal, else null.
     */
    public function yearOrLiteral(): ?string
    {
        if ($this->year === null) {
            return $this->literal;
        }
        if ($this->until !== null && $this->until->year !== $this->year) {
            return $this->year . '–' . $this->until->year;
        }
        return (string) $this->year;
    }

    public function hasYear(): bool
    {
        return $this->year !== null;
    }

    /** ISO 8601 as precise as recorded ("2018", "2018-12", "2018-12-07"); null without a year. */
    public function iso(): ?string
    {
        return $this->joined('-');
    }

    /**
     * The same, slash-separated ("2018/12/07"): Google Scholar's
     * citation_publication_date and RIS DA.
     */
    public function slashed(): ?string
    {
        return $this->joined('/');
    }

    /**
     * An ISO 8601 interval for an interval ("1950-01-01/1962-01-01", which
     * biblatex reads as a date range), else {@see iso()}.
     */
    public function isoRange(): ?string
    {
        $start = $this->iso();
        if ($start === null || $this->until === null) {
            return $start;
        }
        return $start . '/' . $this->until->iso();
    }

    /**
     * CSL-JSON `date-parts`: one array per end, [year(, month(, day))], or
     * none without a year.
     *
     * @return array<int,array<int,int>>
     */
    public function dateParts(): array
    {
        if ($this->year === null) {
            return [];
        }
        $parts = [$this->parts()];
        if ($this->until !== null) {
            $parts[] = $this->until->parts();
        }
        return $parts;
    }

    /** @return array<int,int> */
    private function parts(): array
    {
        $parts = [(int) $this->year];
        if ($this->month !== null) {
            $parts[] = $this->month;
            if ($this->day !== null) {
                $parts[] = $this->day;
            }
        }
        return $parts;
    }

    private function joined(string $separator): ?string
    {
        if ($this->year === null) {
            return null;
        }
        $out = sprintf('%04d', $this->year);
        if ($this->month !== null) {
            $out .= sprintf('%s%02d', $separator, $this->month);
            if ($this->day !== null) {
                $out .= sprintf('%s%02d', $separator, $this->day);
            }
        }
        return $out;
    }

    /** @return array{year:?int,month:?int,day:?int,literal:?string,until?:array{year:?int,month:?int,day:?int,literal:?string}} */
    public function toArray(): array
    {
        $out = [
            'year'    => $this->year,
            'month'   => $this->month,
            'day'     => $this->day,
            'literal' => $this->literal,
        ];
        if ($this->until !== null) {
            $until = $this->until->toArray();
            unset($until['until']);
            $out['until'] = $until;
        }
        return $out;
    }

    /** @param array<string,mixed> $data the inverse of {@see toArray()} */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['year']) ? (int) $data['year'] : null,
            isset($data['month']) ? (int) $data['month'] : null,
            isset($data['day']) ? (int) $data['day'] : null,
            isset($data['literal']) ? (string) $data['literal'] : null,
            is_array($data['until'] ?? null) ? self::fromArray($data['until']) : null,
        );
    }
}
