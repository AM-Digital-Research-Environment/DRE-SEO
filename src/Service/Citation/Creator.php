<?php
declare(strict_types=1);

namespace DRESeo\Service\Citation;

/**
 * One author or editor of a citable work.
 *
 * DRE person records display as "First Last", so a name is split on the last
 * whitespace-delimited token; an already-inverted "Family, Given" literal is
 * detected by its comma. Institutions — creators linked to an Organisation
 * authority record — are the exception that drives the shape of this class:
 * they must survive as a single field, never split or inverted, or "Africa
 * Multiple Cluster of Excellence" is filed under the surname "Excellence".
 *
 * The literal is always kept, so every consumer can fall back to the name
 * exactly as the archive records it.
 */
final class Creator
{
    private function __construct(
        public readonly ?string $family,
        public readonly ?string $given,
        public readonly string $literal,
        public readonly bool $isInstitution,
    ) {
    }

    /** A creator whose name is one indivisible field. */
    public static function institution(string $literal): self
    {
        return new self(null, null, $literal, true);
    }

    public static function person(?string $family, ?string $given, string $literal): self
    {
        return new self($family, ($given ?? '') !== '' ? $given : null, $literal, false);
    }

    /**
     * Parse a display name. $isInstitution short-circuits the split — the
     * caller knows, from the linked authority record's template, what this is.
     */
    public static function parse(string $label, bool $isInstitution): self
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($isInstitution) {
            return self::institution($label);
        }
        if (str_contains($label, ',')) {
            $bits = array_map('trim', explode(',', $label, 2));
            return self::person($bits[0] !== '' ? $bits[0] : $label, $bits[1] ?? null, $label);
        }
        $parts = explode(' ', $label);
        if (count($parts) === 1) {
            return self::person($label, null, $label);
        }
        $family = array_pop($parts);
        return self::person($family, implode(' ', $parts), $label);
    }

    /**
     * Whether the name must be emitted as one field: an institution, or a
     * literal that could not be split.
     */
    public function isSingleField(): bool
    {
        return $this->isInstitution || $this->family === null;
    }

    /** @return array{family:?string,given:?string,literal:string,isInstitution:bool} */
    public function toArray(): array
    {
        return [
            'family'        => $this->family,
            'given'         => $this->given,
            'literal'       => $this->literal,
            'isInstitution' => $this->isInstitution,
        ];
    }

    /** @param array<string,mixed> $data the inverse of {@see toArray()} */
    public static function fromArray(array $data): self
    {
        $literal = (string) ($data['literal'] ?? '');
        if (!empty($data['isInstitution'])) {
            return self::institution($literal);
        }
        $family = $data['family'] ?? null;
        return self::person(
            $family !== null ? (string) $family : null,
            isset($data['given']) ? (string) $data['given'] : null,
            $literal
        );
    }
}
