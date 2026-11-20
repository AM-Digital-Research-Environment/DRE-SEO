<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * The one-line description a resource page offers search engines and link
 * previews — the <meta name="description">, og:description and the JSON-LD
 * `description` — so the three never disagree.
 *
 * The record's own description or abstract comes first. A record without one
 * gets a sentence built from what it does have, rather than a boilerplate that
 * repeats the title: a work names its type, its first authors, its year and its
 * container ("Book chapter by Ute Fendler and Marie-Anne Kohl (2024), in Of
 * Worlds and Artworks."); a descriptive record names what kind of record it is
 * and where ("Aas, Norbert — person record in the Africa Multiple …").
 */
final class ResourceSummary
{
    use ResourceValueReader;

    public function __construct(private readonly CitationKindMap $kinds)
    {
    }

    /** The record's own description, tags stripped and whitespace collapsed; null when it has none. */
    public function own(AbstractResourceEntityRepresentation $resource): ?string
    {
        $text = $this->firstString($resource, self::DESCRIPTION_TERMS);
        if ($text === null && $resource instanceof MediaRepresentation) {
            // A media page describes its file; failing that, the item it belongs to.
            $text = $this->firstString($resource->item(), self::DESCRIPTION_TERMS);
        }
        return $text !== null ? trim(preg_replace('/\s+/u', ' ', $text) ?? $text) : null;
    }

    /** The own description, else a generated one. Never empty. */
    public function describe(AbstractResourceEntityRepresentation $resource, string $siteTitle): string
    {
        return $this->own($resource) ?? $this->generated($resource, $siteTitle);
    }

    /** A sentence built from the record's metadata, for a record with no description. */
    public function generated(AbstractResourceEntityRepresentation $resource, string $siteTitle): string
    {
        $title = trim((string) $resource->displayTitle());
        $kind = $this->kinds->forResource($resource);
        $where = $siteTitle !== '' ? ' in ' . $siteTitle : '';

        if ($kind->isAuthorityRecord()) {
            $label = mb_strtolower((string) $kind->entryLabel());
            return sprintf('%s — %s%s.', $title !== '' ? $title : 'Untitled', $label, $where);
        }

        $class = $resource->resourceClass();
        $type = $class ? $this->ucfirst(trim((string) $class->label())) : 'Record';
        $sentence = $type;

        $names = array_map(
            function (array $person): string {
                // An organisation keeps its name ("Institute of African
                // Studies, University of Bayreuth"); a person is named in
                // natural order.
                $creator = Creator::parse($person['label'], $this->kinds->isOrganization($person['linked']));
                return $creator->given !== null ? $creator->given . ' ' . $creator->family : (string) $creator->literal;
            },
            $this->agentsOf($resource, $this->kinds->forResource($resource)->authorTerms())
        );
        if ($names !== []) {
            $sentence .= ' by ' . match (true) {
                count($names) === 1 => $names[0],
                count($names) === 2 => $names[0] . ' and ' . $names[1],
                default => $names[0] . ', ' . $names[1] . ' et al.',
            };
        }
        $date = $this->firstString($resource, self::DATE_TERMS);
        if ($date !== null && preg_match('/\d{4}/', $date, $m)) {
            $sentence .= ' (' . $m[0] . ')';
        }
        $container = $this->firstLabel($resource, 'dcterms:isPartOf');
        if ($container !== null) {
            $sentence .= ', in ' . preg_replace('/\s+:\s+/u', ': ', $container);
        }
        if ($sentence === $type) {
            // Nothing beyond the type: say what the record is and where.
            return sprintf('%s — %s%s.', $title !== '' ? $title : 'Untitled', mb_strtolower($type), $where);
        }
        return rtrim($sentence, '.') . '.';
    }

    private function ucfirst(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
