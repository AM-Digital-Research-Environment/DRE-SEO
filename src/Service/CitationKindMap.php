<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Resolves an Omeka **resource template** id to a {@see CitationKind}, from the
 * configured `dre_seo.citation.template_kinds` map.
 *
 * Template, not resource class: the DRE syncs one template per entity type and
 * one per publication type (11-20), plus podcasts (21) and YouTube videos (22),
 * and several of those share an RDF class — so the template is the finer and
 * authoritative signal. {@see CitationMeta} keys off the same map.
 *
 * A template_kinds value that is not a known kind (a config typo) resolves to
 * the default rather than to a kind nothing handles.
 */
final class CitationKindMap
{
    /** @var array<int,CitationKind> */
    private array $byTemplateId = [];

    private readonly CitationKind $default;

    /**
     * @param array<int|string,string> $templateKinds resource template id => kind name
     */
    public function __construct(array $templateKinds, string $defaultKind = 'item')
    {
        $this->default = CitationKind::tryFrom($defaultKind) ?? CitationKind::Item;
        foreach ($templateKinds as $templateId => $kind) {
            $resolved = CitationKind::tryFrom((string) $kind);
            if ($resolved !== null) {
                $this->byTemplateId[(int) $templateId] = $resolved;
            }
        }
    }

    /** The kind for a resource template id; the default for null/unmapped/unknown. */
    public function forTemplateId(?int $templateId): CitationKind
    {
        return $templateId === null
            ? $this->default
            : ($this->byTemplateId[$templateId] ?? $this->default);
    }

    /** The kind for a resource, read from its resource template. */
    public function forResource(?AbstractResourceEntityRepresentation $resource): CitationKind
    {
        return $this->forTemplateId(self::templateId($resource));
    }

    /**
     * Whether a linked authority record is an Organisation. Institutional
     * creators keep a single-field name and are never split or inverted.
     */
    public function isOrganization(?AbstractResourceEntityRepresentation $linked): bool
    {
        return $linked !== null && $this->forResource($linked) === CitationKind::Organization;
    }

    /** A resource's template id, or null when it carries no template. */
    public static function templateId(?AbstractResourceEntityRepresentation $resource): ?int
    {
        $template = $resource ? $resource->resourceTemplate() : null;

        return $template ? $template->id() : null;
    }
}
