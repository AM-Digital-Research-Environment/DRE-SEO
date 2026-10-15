<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * A resource's Linked Data identity — its permanent URI
 * (`/resource/{id}`), the RDF documents that describe it and the authority
 * IRIs it is the same as — and the dataset description, as the DRE Linked
 * Data module publishes them through its `dreLinkedData` view helper.
 *
 * That module is optional. Without it, or for a resource it does not publish
 * (anything not publicly visible), every lookup comes back empty and the
 * structured data and Signposting keep the identities they had before. The
 * helper is looked up once, on first use, so nothing is resolved on requests
 * that never ask, and each resource is described once per request: a record
 * page asks about the same author or container from the JSON-LD, the
 * Signposting links and the citation alike.
 *
 * DRE Linked Data 0.3.0 added `page`, `sameAs` and `dataset()`. Each is read
 * only when present, so 0.2.0 keeps working: {@see sameAs()} answers null (not
 * an empty list) when the helper does not say, and the caller falls back to
 * its own reading.
 */
final class LinkedDataIdentity
{
    /** @var callable|null */
    private $helper = null;
    private bool $resolved = false;

    /** @var array<int|string,array<string,mixed>> described resources, by id */
    private array $described = [];

    /** @var array<string,mixed>|null */
    private ?array $dataset = null;

    /**
     * @param \Closure|null $resolveHelper returns the `dreLinkedData` helper,
     *                                     or null when the module is absent
     */
    public function __construct(private readonly ?\Closure $resolveHelper = null)
    {
    }

    /** The resource's permanent URI, or null when DRE Linked Data does not publish it. */
    public function uri(?AbstractResourceEntityRepresentation $resource): ?string
    {
        return $resource ? $this->string($this->describe($resource)['uri'] ?? null) : null;
    }

    /** The resource's page on the default site, as DRE Linked Data names it (0.3.0+). */
    public function page(AbstractResourceEntityRepresentation $resource): ?string
    {
        return $this->string($this->describe($resource)['page'] ?? null);
    }

    /**
     * The canonical authority IRIs the resource is the same as (Wikidata, GND,
     * ORCID, …, and https://doi.org/ for a DOI), as DRE Linked Data publishes
     * them. Null when the helper does not say — DRE Linked Data before 0.3.0,
     * or a resource it does not publish — so the caller can tell "none" from
     * "not known".
     *
     * @return string[]|null
     */
    public function sameAs(AbstractResourceEntityRepresentation $resource): ?array
    {
        $described = $this->describe($resource);
        if (!isset($described['sameAs']) || !is_array($described['sameAs'])) {
            return null;
        }
        $out = [];
        foreach ($described['sameAs'] as $iri) {
            $iri = $this->string($iri);
            if ($iri !== null && preg_match('#\Ahttps?://\S+\z#i', $iri)) {
                $out[$iri] = $iri;
            }
        }
        return array_values($out);
    }

    /**
     * The RDF documents about the resource, in the module's order (Turtle,
     * N-Triples, JSON-LD).
     *
     * @return array<int,array{label:string,type:string,url:string}>
     */
    public function documents(AbstractResourceEntityRepresentation $resource): array
    {
        $documents = [];
        foreach ($this->describe($resource)['downloads'] ?? [] as $document) {
            if (is_array($document) && !empty($document['url']) && !empty($document['type'])) {
                $documents[] = [
                    'label' => (string) ($document['label'] ?? ''),
                    'type'  => (string) $document['type'],
                    'url'   => (string) $document['url'],
                ];
            }
        }
        return $documents;
    }

    /**
     * The whole dataset, as `dreLinkedData()->dataset()` describes it (0.3.0+):
     * uri, void, title, description, licence, publisher, modified, triples,
     * entities, distributions. Empty when the module is absent, older, or
     * cannot describe it.
     *
     * @return array<string,mixed>
     */
    public function dataset(): array
    {
        if ($this->dataset !== null) {
            return $this->dataset;
        }
        $this->dataset = [];
        $helper = $this->helper();
        if ($helper === null || !is_object($helper) || !method_exists($helper, 'dataset')) {
            return $this->dataset;
        }
        try {
            $dataset = $helper->dataset();
        } catch (\Throwable $e) {
            return $this->dataset;
        }
        $this->dataset = is_array($dataset) ? $dataset : [];
        return $this->dataset;
    }

    /** @return array<string,mixed> */
    private function describe(AbstractResourceEntityRepresentation $resource): array
    {
        $helper = $this->helper();
        if ($helper === null) {
            return [];
        }
        try {
            $key = $resource->id();
        } catch (\Throwable $e) {
            $key = null;
        }
        if ($key !== null && isset($this->described[$key])) {
            return $this->described[$key];
        }
        try {
            $described = $helper($resource);
        } catch (\Throwable $e) {
            // A failing optional module must never take the page down.
            $described = [];
        }
        $described = is_array($described) ? $described : [];
        if ($key !== null) {
            $this->described[$key] = $described;
        }
        return $described;
    }

    private function helper(): ?callable
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $helper = $this->resolveHelper ? ($this->resolveHelper)() : null;
            } catch (\Throwable $e) {
                $helper = null;
            }
            $this->helper = is_callable($helper) ? $helper : null;
        }
        return $this->helper;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
