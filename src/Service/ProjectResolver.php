<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Which research project a record belongs to, and which project an item set
 * stands for.
 *
 * AMIRA describes each research project twice: as an item (class
 * `foaf:Project`, in the Projects item set) and as the item set of the
 * project's collection. The project item is the page a reader should land on,
 * so the breadcrumb places a research item under its project, and a project's
 * item-set page redirects there.
 *
 *   • A record's project is the first of its `dcterms:isPartOf` values that
 *     links to a public `foaf:Project` item — the rule DRE-theme's
 *     RecordAccess and the research-item access statement apply.
 *   • An item set's project is the same, read on the item set's own
 *     `dcterms:isPartOf`; until the sync writes that link, the public
 *     `foaf:Project` item whose `dre:id` equals the item set's.
 *
 * A project that is private, or not visible to the current user, is no
 * project: the caller keeps its ordinary behaviour.
 */
final class ProjectResolver
{
    /** The resource class every project item carries. */
    public const PROJECT_CLASS = 'foaf:Project';

    /** Site setting: redirect a project's item-set page to the project (default on). */
    public const REDIRECT_SETTING = 'dre_seo_project_redirect';

    /** @var array<int,ItemRepresentation|null> item set id => its project */
    private array $itemSetProjects = [];

    /**
     * @param \Closure(string):iterable<mixed>|null $findByDreId the public items
     *        whose dre:id is the given value (the API search, in Omeka)
     * @param \Closure(int):mixed|null $redirectSetting the site's
     *        dre_seo_project_redirect setting, by site id (null when unset)
     */
    public function __construct(
        private readonly ?\Closure $findByDreId = null,
        private readonly ?\Closure $redirectSetting = null,
    ) {
    }

    /** Whether a record is a project item: an item of class foaf:Project. */
    public static function isProject(?AbstractResourceEntityRepresentation $resource): bool
    {
        if (!$resource instanceof ItemRepresentation) {
            return false;
        }
        try {
            $class = $resource->resourceClass();
            return $class !== null && $class->term() === self::PROJECT_CLASS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The public project a record is part of: the first `dcterms:isPartOf`
     * value linking to one. Never the record itself.
     */
    public function projectOf(AbstractResourceEntityRepresentation $resource): ?ItemRepresentation
    {
        try {
            $values = $resource->value('dcterms:isPartOf', ['all' => true]);
        } catch (\Throwable $e) {
            return null;
        }
        foreach ((array) $values as $value) {
            $linked = $value instanceof ValueRepresentation ? $value->valueResource() : null;
            if ($linked instanceof ItemRepresentation
                && self::isProject($linked)
                && self::isPublic($linked)
                && !self::same($linked, $resource)
            ) {
                return $linked;
            }
        }
        return null;
    }

    /**
     * The public project an item set stands for: its own `dcterms:isPartOf`
     * project, else the public project item that shares its `dre:id`.
     */
    public function projectOfItemSet(ItemSetRepresentation $itemSet): ?ItemRepresentation
    {
        $id = $itemSet->id();
        if (array_key_exists($id, $this->itemSetProjects)) {
            return $this->itemSetProjects[$id];
        }
        return $this->itemSetProjects[$id] = $this->projectOf($itemSet) ?? $this->projectByDreId($itemSet);
    }

    /** Whether the site redirects a project's item-set page to the project. On unless switched off. */
    public function redirectsEnabled(int $siteId): bool
    {
        if ($this->redirectSetting === null) {
            return true;
        }
        try {
            $value = ($this->redirectSetting)($siteId);
        } catch (\Throwable $e) {
            return true;
        }
        return $value === null || $value === '' || $value === '1' || $value === 1 || $value === true;
    }

    /**
     * The project an item set's page redirects to in this site: its public
     * project, when the site redirects. Null otherwise.
     */
    public function redirectTarget(ItemSetRepresentation $itemSet, SiteRepresentation $site): ?ItemRepresentation
    {
        if (!$this->redirectsEnabled((int) $site->id())) {
            return null;
        }
        return $this->projectOfItemSet($itemSet);
    }

    /**
     * Where a request for an item-set page goes instead: the project's page in
     * the same site, for a GET or HEAD of the public `site/item-set` route.
     * Null — no redirect — for any other route or method, an item set that
     * cannot be read, one with no public project, or when the site keeps its
     * item-set pages.
     */
    public function itemSetRedirectUrl(
        string $method,
        string $routeName,
        ?ItemSetRepresentation $itemSet,
        SiteRepresentation $site
    ): ?string {
        if ($routeName !== 'site/item-set' || !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }
        if ($itemSet === null) {
            return null;
        }
        $project = $this->redirectTarget($itemSet, $site);
        if ($project === null) {
            return null;
        }
        try {
            $url = (string) $project->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
        return $url !== '' ? $url : null;
    }

    /** The public project item that carries the item set's dre:id. */
    private function projectByDreId(ItemSetRepresentation $itemSet): ?ItemRepresentation
    {
        if ($this->findByDreId === null) {
            return null;
        }
        try {
            $value = $itemSet->value('dre:id');
            $dreId = $value instanceof ValueRepresentation ? trim(strip_tags((string) $value)) : '';
            if ($dreId === '') {
                return null;
            }
            foreach (($this->findByDreId)($dreId) as $candidate) {
                if ($candidate instanceof ItemRepresentation && self::isProject($candidate) && self::isPublic($candidate)) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private static function isPublic(AbstractResourceEntityRepresentation $resource): bool
    {
        try {
            return !method_exists($resource, 'isPublic') || (bool) $resource->isPublic();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function same(AbstractResourceEntityRepresentation $a, AbstractResourceEntityRepresentation $b): bool
    {
        return get_class($a) === get_class($b) && $a->id() === $b->id();
    }
}
