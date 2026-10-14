<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * A resource's Linked Data identity — its permanent URI
 * (`/resource/{id}`) and the RDF documents that describe it — as the DRE
 * Linked Data module publishes them through its `dreLinkedData` view helper.
 *
 * That module is optional. Without it, or for a resource it does not publish
 * (anything not publicly visible), every lookup comes back empty and the
 * structured data and Signposting keep the identities they had before. The
 * helper is looked up once, on first use, so nothing is resolved on requests
 * that never ask.
 */
final class LinkedDataIdentity
{
    /** @var callable|null */
    private $helper = null;
    private bool $resolved = false;

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
        $uri = $resource ? ($this->describe($resource)['uri'] ?? null) : null;
        return is_string($uri) && $uri !== '' ? $uri : null;
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

    /** @return array<string,mixed> */
    private function describe(AbstractResourceEntityRepresentation $resource): array
    {
        $helper = $this->helper();
        if ($helper === null) {
            return [];
        }
        try {
            $described = $helper($resource);
        } catch (\Throwable $e) {
            // A failing optional module must never take the page down.
            return [];
        }
        return is_array($described) ? $described : [];
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
}
