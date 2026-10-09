<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds schema.org JSON-LD documents for resource and site pages.
 *
 * The resource template id selects the @type (see the 'dre_seo.structured_data'
 * config map). Descriptive records get an entity shape — a Person, Place,
 * Organization, ResearchProject, DefinedTerm (a subject heading or other
 * vocabulary term) or Periodical (a journal) — with their authority links as
 * `sameAs`. Everything else gets a scholarly "creative work" shape: authors in
 * natural name order, dates, identifiers, subjects, language, coverage,
 * containment and funding. All values are read through the representation API,
 * so labels of linked authority items come for free via displayTitle().
 *
 * Google renders only a few of these types as rich results (Article, Video,
 * Dataset in Dataset Search, Breadcrumb); the rest still state what each page
 * is about for any consumer that reads schema.org.
 */
class StructuredData
{
    use ResourceValueReader;

    /** Types that describe a thing rather than a work: they take no authors, dates or containers. */
    private const ENTITY_TYPES = [
        'Person', 'Place', 'Organization', 'ResearchOrganization', 'ResearchProject',
        'DefinedTerm', 'Periodical',
    ];

    /** Article types whose container is a periodical. */
    private const PERIODICAL_PARTS = ['ScholarlyArticle', 'NewsArticle', 'Article', 'BlogPosting'];

    /** @param array<int,string> $templateTypes resource template id => schema @type */
    public function __construct(
        private readonly array $templateTypes,
        private readonly string $defaultType = 'CreativeWork',
        private readonly ?CitationKindMap $kinds = null,
    ) {
    }

    /**
     * @param string|null $image       the resource's own image — never a site-wide
     *                                 fallback, which would claim the site logo
     *                                 depicts the person or the book
     * @param string|null $description the page description, when the caller has
     *                                 already worked it out
     * @return array<mixed>
     */
    public function forResource(
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical,
        ?string $image,
        ?string $description = null
    ): array {
        $type = $this->typeFor($resource);

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'name'     => (string) $resource->displayTitle(),
        ];
        if ($canonical) {
            $data['url'] = $canonical;
        }
        if ($image) {
            $data['image'] = $image;
            if ($type === 'VideoObject') {
                // Google requires thumbnailUrl for video rich results. Omeka's
                // YouTube ingester exposes the video thumbnail as primary media.
                $data['thumbnailUrl'] = $image;
            }
        }
        // description is required for Dataset and universally useful; fall back
        // to a typed sentence — mirroring the <meta name="description"> — so it
        // is never absent. Dataset Search reads at most 5000 characters.
        $data['description'] = $this->clip(
            $description
                ?? $this->firstString($resource, ['dcterms:description', 'dcterms:abstract', 'bibo:abstract'])
                ?? $this->fallbackDescription($resource)
        );

        $sameAs = $this->identityUris($resource);
        if ($type === 'VideoObject') {
            $watchUrl = $this->uris($resource, 'fabio:hasURL')[0] ?? null;
            if ($watchUrl !== null) {
                $sameAs[] = $watchUrl;
                $sameAs = array_values(array_unique($sameAs));
            }
        }
        if ($sameAs) {
            $data['sameAs'] = $sameAs;
        }

        if (in_array($type, self::ENTITY_TYPES, true)) {
            $this->decorateEntity($data, $type, $resource, $site);
        } else {
            $this->decorateWork($data, $type, $resource, $site);
        }

        $modified = $this->modifiedAtom($resource);
        if ($modified !== null) {
            $data['dateModified'] = $modified;
        }

        return $data;
    }

    /** The schema.org @type a resource is published as, from its template. */
    public function typeFor(AbstractResourceEntityRepresentation $resource): string
    {
        $templateId = $resource->resourceTemplate() ? $resource->resourceTemplate()->id() : null;

        return $this->templateTypes[$templateId] ?? $this->defaultType;
    }

    /** @return array<mixed>|null */
    public function breadcrumb(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical
    ): ?array {
        if (!$canonical) {
            return null;
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => $site->title(),
                    'item'     => $this->homeUrl($view, $site),
                ],
                [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => (string) $resource->displayTitle(),
                    'item'     => $canonical,
                ],
            ],
        ];
    }

    /**
     * The home page's WebSite node, which Google reads for the site name. The
     * SearchAction it once carried fed the sitelinks search box, which Google
     * retired in November 2024, so it is no longer emitted.
     *
     * @return array<mixed>
     */
    public function webSite(PhpRenderer $view, SiteRepresentation $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $site->title(),
            'url'      => $this->homeUrl($view, $site),
        ];
    }

    /**
     * An item set's landing page: a CollectionPage about the set.
     *
     * @return array<mixed>
     */
    public function collectionPage(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $itemSet,
        SiteRepresentation $site,
        string $url,
        string $description
    ): array {
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => (string) $itemSet->displayTitle(),
            'url'         => $url,
            'description' => $this->clip($description),
            'isPartOf'    => ['@type' => 'WebSite', 'name' => $site->title(), 'url' => $this->homeUrl($view, $site)],
        ];
    }

    // ─── @type-specific decoration ──────────────────────────────────────────

    /** @param array<mixed> $data */
    private function decorateEntity(
        array &$data,
        string $type,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        $alternate = $this->firstString($resource, ['dcterms:alternative']);

        switch ($type) {
            case 'Person':
                // Records are titled "Surname, Forename"; schema.org's name is
                // the name as written, with the parts alongside.
                $creator = Creator::parse((string) $resource->displayTitle(), false);
                if ($creator->given !== null) {
                    $data['name'] = $creator->given . ' ' . $creator->family;
                    $data['givenName'] = $creator->given;
                    $data['familyName'] = $creator->family;
                    $data['alternateName'] = $creator->literal;
                }
                $affiliation = $this->firstLink($resource, 'dcterms:isPartOf', $site);
                if ($affiliation) {
                    $data['affiliation'] = array_filter(['@type' => 'Organization'] + $affiliation);
                }
                break;

            case 'Place':
                $lat = $this->firstString($resource, ['schema:latitude', 'geo:lat']);
                $lng = $this->firstString($resource, ['schema:longitude', 'geo:long']);
                if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
                    $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
                }
                $within = $this->firstLink($resource, 'dcterms:isPartOf', $site);
                if ($within) {
                    $data['containedInPlace'] = array_filter(['@type' => 'Place'] + $within);
                }
                break;

            case 'ResearchProject':
            case 'ResearchOrganization':
            case 'Organization':
                $parent = $this->firstLink($resource, 'dcterms:isPartOf', $site);
                if ($parent) {
                    $data['parentOrganization'] = array_filter(['@type' => 'Organization'] + $parent);
                }
                $funders = $this->links($resource, ['frapo:isFundedBy'], $site, 'Organization');
                if ($funders) {
                    $data['funder'] = $funders;
                }
                $members = $this->links($resource, ['foaf:member'], $site, 'Person');
                if ($members) {
                    $data['member'] = $members;
                }
                $identifier = $this->firstString($resource, ['dre:id']);
                if ($identifier !== null && $type !== 'Organization') {
                    $data['identifier'] = $identifier;
                }
                break;

            case 'DefinedTerm':
                // Subjects, languages, genres and tags share one template; the
                // vocabulary each belongs to is its dcterms:type.
                $scheme = $this->firstLabel($resource, 'dcterms:type');
                if ($scheme !== null) {
                    $data['inDefinedTermSet'] = ['@type' => 'DefinedTermSet', 'name' => $scheme];
                }
                break;

            case 'Periodical':
                $issn = $this->firstString($resource, ['bibo:issn', 'bibo:eissn']);
                if ($issn !== null) {
                    $data['issn'] = $issn;
                }
                break;
        }
        if ($alternate !== null && !isset($data['alternateName'])) {
            $data['alternateName'] = $alternate;
        }
    }

    /** @param array<mixed> $data */
    private function decorateWork(
        array &$data,
        string $type,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        // marcrel:hst / :spk are the podcast host / guest(s) and a video's
        // speakers; marcrel:cre is a research item's creator. They trail the
        // scholarly author roles so they only fill in when those are absent.
        $authors = $this->links(
            $resource,
            ['bibo:authorList', 'dcterms:creator', 'marcrel:aut', 'marcrel:cre', 'marcrel:hst', 'marcrel:spk'],
            $site,
            'Person'
        );
        if ($authors) {
            // Google reads a Dataset's authors from `creator`; every other work
            // type uses `author`.
            $data[$type === 'Dataset' ? 'creator' : 'author'] = $authors;
        }
        // marcrel:sde is the podcast sound engineer (a production contributor).
        $contributors = $this->links($resource, ['dcterms:contributor', 'bibo:editorList', 'marcrel:edt', 'marcrel:sde'], $site, 'Person');
        if ($contributors) {
            $data[in_array($type, ['Book', 'Chapter', 'PublicationIssue', 'CreativeWorkSeries'], true) ? 'editor' : 'contributor'] = $contributors;
        }

        $date = $this->firstString($resource, ['dcterms:issued', 'dcterms:date']);
        if ($date !== null) {
            $data[$type === 'VideoObject' ? 'uploadDate' : 'datePublished'] = $date;
        } else {
            $created = $this->firstString($resource, ['dcterms:created']);
            if ($created !== null) {
                $data['dateCreated'] = $created;
            }
        }

        $language = $this->firstLabel($resource, 'dcterms:language');
        if ($language !== null) {
            $data['inLanguage'] = $language;
        }

        $subjects = $this->labels($resource, 'dcterms:subject');
        if ($subjects) {
            $data['keywords'] = implode(', ', $subjects);
        }

        $places = $this->labels($resource, 'dcterms:spatial');
        if ($places) {
            $data['spatialCoverage'] = array_map(
                static fn (string $name) => ['@type' => 'Place', 'name' => $name],
                $places
            );
        }

        $doi = $this->doi($resource);
        if ($doi !== null) {
            $data['identifier'] = 'https://doi.org/' . $doi;
        }
        $isbn = $this->isbn($resource);
        if ($isbn !== null && $type === 'Book') {
            $data['isbn'] = $isbn;
        }
        [$pageFirst, $pageLast] = $this->pageBounds($resource);
        if (in_array($type, ['ScholarlyArticle', 'NewsArticle', 'Article', 'Chapter'], true)) {
            if ($pageFirst !== null) {
                $data['pageStart'] = $pageFirst;
            }
            if ($pageLast !== null) {
                $data['pageEnd'] = $pageLast;
            }
        }

        $partOf = $this->firstLink($resource, 'dcterms:isPartOf', $site);
        if ($partOf) {
            $this->containment($data, $type, $partOf, $resource, $isbn);
        }

        $publisher = $this->firstLabel($resource, 'dcterms:publisher');
        if ($publisher !== null) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }
        if ($type === 'Thesis') {
            $grantor = $this->firstLabel($resource, 'marcrel:dgg');
            if ($grantor !== null) {
                $data['sourceOrganization'] = ['@type' => 'Organization', 'name' => $grantor];
            }
        }
        $funders = $this->links($resource, ['frapo:isFundedBy'], $site, 'Organization');
        if ($funders) {
            $data['funder'] = $funders;
        }

        // license is a recommended field for Dataset and valid on any creative
        // work; emit it whenever the item carries one (a linked CC-license
        // authority item, a URI, or literal text). No default is asserted.
        $license = $this->licenseValue($resource);
        if ($license !== null) {
            $data['license'] = $license;
        }

        $duration = $this->firstString($resource, ['dcterms:extent']);
        if ($duration !== null && preg_match('/\AP(?:\d|T\d)/', $duration)) {
            $data['duration'] = $duration;
        }

        if ($type === 'VideoObject') {
            $embed = $this->youTubeEmbed($resource);
            if ($embed !== null) {
                // Google takes embedUrl when the file itself (contentUrl) is
                // not published, as for a YouTube video.
                $data['embedUrl'] = $embed;
            }
        }
        if ($type === 'PodcastEpisode') {
            $episode = $this->firstString($resource, ['bibo:number']);
            if ($episode !== null) {
                $data['episodeNumber'] = $episode;
            }
        }
        if ($type === 'Dataset') {
            $this->datasetDistribution($data, $resource, $site);
        }
    }

    /**
     * The container, typed for what it is: an article's periodical (nested in
     * its volume and issue when those are known), a chapter's book, a podcast
     * episode's series; any other work just isPartOf a CreativeWork.
     *
     * @param array<mixed> $data
     * @param array{name:string,url:?string} $partOf
     */
    private function containment(
        array &$data,
        string $type,
        array $partOf,
        AbstractResourceEntityRepresentation $resource,
        ?string $isbn
    ): void {
        $node = static fn (string $nodeType, array $extra = []): array
            => array_filter(['@type' => $nodeType, 'name' => $partOf['name'], 'url' => $partOf['url']] + $extra);

        if (in_array($type, self::PERIODICAL_PARTS, true)) {
            $periodical = $node('Periodical', array_filter(['issn' => $this->firstString($resource, ['bibo:issn'])]));
            $volume = $this->firstString($resource, ['bibo:volume']);
            $issue = $this->firstString($resource, ['bibo:issue']);
            if ($volume !== null) {
                $periodical = ['@type' => 'PublicationVolume', 'volumeNumber' => $volume, 'isPartOf' => $periodical];
            }
            if ($issue !== null) {
                $periodical = ['@type' => 'PublicationIssue', 'issueNumber' => $issue, 'isPartOf' => $periodical];
            }
            $data['isPartOf'] = $periodical;
            return;
        }
        match ($type) {
            'Chapter' => $data['isPartOf'] = $node('Book', array_filter(['isbn' => $isbn])),
            'PodcastEpisode' => $data['partOfSeries'] = $node('PodcastSeries'),
            'Book' => $data['isPartOf'] = $node('BookSeries'),
            default => $data['isPartOf'] = $node('CreativeWork'),
        };
    }

    /**
     * A Dataset's public files as DataDownload distributions, and the AMIRA
     * site as the catalogue it is listed in.
     *
     * @param array<mixed> $data
     */
    private function datasetDistribution(
        array &$data,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        $data['includedInDataCatalog'] = ['@type' => 'DataCatalog', 'name' => $site->title()];
        if (!$resource instanceof ItemRepresentation) {
            return;
        }
        $downloads = [];
        foreach ($resource->media() as $media) {
            if (method_exists($media, 'isPublic') && !$media->isPublic()) {
                continue;
            }
            $url = method_exists($media, 'originalUrl') ? $media->originalUrl() : null;
            if (!$url) {
                continue;
            }
            $downloads[] = array_filter([
                '@type' => 'DataDownload',
                'contentUrl' => $url,
                'encodingFormat' => method_exists($media, 'mediaType') ? $media->mediaType() : null,
            ]);
        }
        if ($downloads) {
            $data['distribution'] = $downloads;
        }
    }

    // ─── Value readers ──────────────────────────────────────────────────────

    /**
     * A non-empty description when the resource carries no literal one and the
     * caller supplied none, so the field is always present.
     */
    private function fallbackDescription(AbstractResourceEntityRepresentation $resource): string
    {
        $title = trim((string) $resource->displayTitle());
        $classLabel = $resource->resourceClass() ? ucfirst((string) $resource->resourceClass()->label()) : null;
        if ($classLabel && $title !== '') {
            return sprintf('%s: %s.', $classLabel, $title);
        }
        return $title !== '' ? $title . '.' : ($classLabel ?? 'Record') . '.';
    }

    /**
     * The resource's license as a schema.org-acceptable string — a linked
     * license authority item's label (e.g. "CC-BY-NC-SA-4.0"), a URI, or literal
     * text. dcterms:license is preferred over the (unused) dcterms:rights.
     */
    private function licenseValue(AbstractResourceEntityRepresentation $resource): ?string
    {
        foreach (['dcterms:license', 'dcterms:rights'] as $term) {
            $value = $resource->value($term);
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            if ($linked) {
                $label = trim((string) $linked->displayTitle());
                if ($label !== '') {
                    return $label;
                }
                continue;
            }
            $uri = $value->uri();
            if ($uri !== null && $uri !== '') {
                return $uri;
            }
            $text = trim(strip_tags((string) $value));
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    /** The embed URL of a YouTube video, from its watch URL. */
    private function youTubeEmbed(AbstractResourceEntityRepresentation $resource): ?string
    {
        foreach ($this->uris($resource, 'fabio:hasURL') as $uri) {
            if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $uri, $m)) {
                return 'https://www.youtube.com/embed/' . $m[1];
            }
        }
        return null;
    }

    private function modifiedAtom(AbstractResourceEntityRepresentation $resource): ?string
    {
        try {
            $modified = $resource->modified();
        } catch (\Throwable $e) {
            return null;
        }
        return $modified instanceof \DateTimeInterface ? $modified->format(DATE_ATOM) : null;
    }

    /**
     * Linked resources for the first of $terms that has values. A person is
     * named in natural order with the parts alongside; a creator linked to an
     * Organisation record is an Organization, never split.
     *
     * @param string[] $terms
     * @return array<array<string,string>>
     */
    private function links(
        AbstractResourceEntityRepresentation $resource,
        array $terms,
        SiteRepresentation $site,
        string $type
    ): array {
        $out = [];
        foreach ($terms as $term) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $linked = $value->valueResource();
                $label = $linked ? trim((string) $linked->displayTitle()) : trim(strip_tags((string) $value));
                if ($label === '') {
                    continue;
                }
                $isOrganization = $type === 'Organization' || ($this->kinds?->isOrganization($linked) ?? false);
                $node = $this->agent($label, $isOrganization ? 'Organization' : $type);
                if ($linked) {
                    try {
                        $node['url'] = $linked->siteUrl($site->slug(), true);
                    } catch (\Throwable $e) {
                        // no url
                    }
                }
                $out[$label] = $node;
            }
            if ($out) {
                break; // first populated term wins
            }
        }
        return array_values($out);
    }

    /** @return array<string,string> */
    private function agent(string $label, string $type): array
    {
        if ($type !== 'Person') {
            return ['@type' => $type, 'name' => $label];
        }
        $creator = Creator::parse($label, false);
        if ($creator->given === null) {
            return ['@type' => 'Person', 'name' => $label];
        }
        return [
            '@type'      => 'Person',
            'name'       => $creator->given . ' ' . $creator->family,
            'givenName'  => $creator->given,
            'familyName' => (string) $creator->family,
        ];
    }

    /** @return array{name:string,url:?string}|null */
    private function firstLink(
        AbstractResourceEntityRepresentation $resource,
        string $term,
        SiteRepresentation $site
    ): ?array {
        $value = $resource->value($term);
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $linked = $value->valueResource();
        if ($linked) {
            $url = null;
            try {
                $url = $linked->siteUrl($site->slug(), true);
            } catch (\Throwable $e) {
                // ignore
            }
            return ['name' => (string) $linked->displayTitle(), 'url' => $url];
        }
        $name = trim(strip_tags((string) $value));
        return $name !== '' ? ['name' => $name, 'url' => null] : null;
    }

    private function homeUrl(PhpRenderer $view, SiteRepresentation $site): string
    {
        $url = $view->url('site', ['site-slug' => $site->slug()], ['force_canonical' => true]);
        return rtrim($url, '/') . '/';
    }
}
