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
 */
final class IssuedDate
{
    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
        public readonly ?int $day = null,
        public readonly ?string $literal = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Parse a stored timestamp. Anything with a four-digit year yields its
     * parts; anything else is preserved as a literal.
     */
    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::unknown();
        }
        if (preg_match('/(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?/', $raw, $m)) {
            return new self(
                (int) $m[1],
                ($m[2] ?? '') !== '' ? (int) $m[2] : null,
                ($m[3] ?? '') !== '' ? (int) $m[3] : null,
                $raw,
            );
        }
        return new self(null, null, null, $raw);
    }

    /** The year as a string, falling back to the raw literal, else null. */
    public function yearOrLiteral(): ?string
    {
        return $this->year !== null ? (string) $this->year : $this->literal;
    }

    public function hasYear(): bool
    {
        return $this->year !== null;
    }

    /** @return array{year:?int,month:?int,day:?int,literal:?string} */
    public function toArray(): array
    {
        return [
            'year'    => $this->year,
            'month'   => $this->month,
            'day'     => $this->day,
            'literal' => $this->literal,
        ];
    }

    /** @param array<string,mixed> $data the inverse of {@see toArray()} */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['year']) ? (int) $data['year'] : null,
            isset($data['month']) ? (int) $data['month'] : null,
            isset($data['day']) ? (int) $data['day'] : null,
            isset($data['literal']) ? (string) $data['literal'] : null,
        );
    }
}
