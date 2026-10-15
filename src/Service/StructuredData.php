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
 *
 * With DRE Linked Data installed, the record and every linked record it names
 * (authors, containers, affiliations, subjects, places …) carry their permanent
 * resource URI as `@id`, so a harvester reading the page and a Linked Data
 * client dereferencing the URI see one identity. `url` stays the record page.
 * A linked record is typed as its own page types it (a research item's project
 * is a ResearchProject, not a CreativeWork), whatever role it plays here.
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

    /** Google Dataset Search ignores a Dataset whose description is shorter. */
    public const DATASET_DESCRIPTION_MIN = 50;

    /**
     * What a research item's conditionsOfAccess says after its access rights,
     * unless the dre_seo_access_statement setting says otherwise.
     */
    public const ACCESS_STATEMENT = 'AMIRA holds the description of this material, not the material itself. Requests for access go to the research project named in the record.'; // @translate

    private ?string $resolvedAccessStatement = null;

    /**
     * @param array<int,string> $templateTypes resource template id => schema @type
     * @param array{access_free?:array<string,bool>,access_statement?:\Closure|string|null} $options
     *        access_free: access-right label => whether that access is free;
     *        access_statement: the sentence a research item's conditionsOfAccess
     *        ends with (a closure is resolved on first use, so it is translated
     *        in the page's locale)
     */
    public function __construct(
        private readonly array $templateTypes,
        private readonly string $defaultType = 'CreativeWork',
        private readonly ?CitationKindMap $kinds = null,
        private readonly ?LinkedDataIdentity $identity = null,
        private readonly array $options = [],
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

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@id'      => $this->identity?->uri($resource),
            '@type'    => $type,
            'name'     => (string) $resource->displayTitle(),
        ], static fn ($value) => $value !== null);
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

        $sameAs = $this->sameAsFor($resource);
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

    /**
     * The IRIs a record is the same as: DRE Linked Data's canonical identity
     * links when it publishes them, else the allowlisted authority IRIs the
     * record itself carries; and a work's DOI, as its https://doi.org/ IRI.
     *
     * @return string[]
     */
    public function sameAsFor(AbstractResourceEntityRepresentation $resource): array
    {
        $sameAs = $this->identity?->sameAs($resource) ?? $this->identityUris($resource);
        $doi = $this->doi($resource);
        if ($doi !== null) {
            $sameAs[] = 'https://doi.org/' . $doi;
        }
        return array_values(array_unique($sameAs));
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
     * The whole catalogue as a schema.org Dataset, for Google Dataset Search,
     * from DRE Linked Data's description of its dataset (VoID, licence,
     * publisher, dumps). Null when there is nothing to describe, or no
     * description of the length Google requires can be given.
     *
     * @param array<string,mixed> $dataset `dreLinkedData()->dataset()`
     * @return array<mixed>|null
     */
    public function dataset(PhpRenderer $view, SiteRepresentation $site, array $dataset): ?array
    {
        $uri = is_string($dataset['uri'] ?? null) ? trim($dataset['uri']) : '';
        if ($uri === '') {
            return null;
        }
        $home = $this->homeUrl($view, $site);
        $title = trim((string) ($dataset['title'] ?? '')) ?: (string) $site->title();
        $description = $this->clip(trim((string) ($dataset['description'] ?? '')));
        if (mb_strlen($description) < self::DATASET_DESCRIPTION_MIN) {
            $description = trim($description . ' ' . sprintf(
                '%s: the public records of %s, published as Linked Open Data in RDF.',
                $title,
                $site->title()
            ));
        }
        if (mb_strlen($description) < self::DATASET_DESCRIPTION_MIN) {
            return null;
        }

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Dataset',
            '@id'         => $uri,
            'name'        => $title,
            'description' => $description,
            // The page that explains the dataset (DRE Linked Data 0.3.1+), else the home.
            'url'         => self::httpUrl($dataset['landingPage'] ?? null) ?? $home,
        ];
        $licence = is_string($dataset['licence'] ?? null) ? trim($dataset['licence']) : '';
        if ($licence !== '') {
            $data['license'] = $licence;
        }
        $publisher = is_array($dataset['publisher'] ?? null) ? $dataset['publisher'] : [];
        $publisherName = trim((string) ($publisher['name'] ?? ''));
        if ($publisherName !== '') {
            $organization = ['@type' => 'Organization', 'name' => $publisherName];
            $publisherUri = is_string($publisher['uri'] ?? null) ? trim($publisher['uri']) : '';
            if ($publisherUri !== '') {
                $organization['sameAs'] = $publisherUri;
            }
            $data['creator'] = $organization;
            $data['publisher'] = $organization;
        }
        $modified = is_string($dataset['modified'] ?? null) ? trim($dataset['modified']) : '';
        if ($modified !== '') {
            $data['dateModified'] = $modified;
        }
        $downloads = [];
        foreach ((array) ($dataset['distributions'] ?? []) as $distribution) {
            if (!is_array($distribution) || empty($distribution['url'])) {
                continue;
            }
            $downloads[] = array_filter([
                '@type'          => 'DataDownload',
                'name'           => isset($distribution['label']) ? (string) $distribution['label'] : null,
                'contentUrl'     => (string) $distribution['url'],
                'encodingFormat' => isset($distribution['type']) ? (string) $distribution['type'] : null,
                'contentSize'    => isset($distribution['bytes']) && is_numeric($distribution['bytes'])
                    ? self::byteSize((int) $distribution['bytes'])
                    : null,
            ], static fn ($value) => $value !== null && $value !== '');
        }
        if ($downloads) {
            $data['distribution'] = $downloads;
        }
        $data['includedInDataCatalog'] = ['@type' => 'DataCatalog', 'name' => $site->title(), 'url' => $home];
        return $data;
    }

    /** An absolute http(s) URL, or null. */
    private static function httpUrl(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        return preg_match('~^https?://~i', $url) ? $url : null;
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
        $name = (string) $itemSet->displayTitle();
        // The page is about the item set, which has an identity of its own:
        // its resource URI, the @id DRE Linked Data describes it under.
        $collection = array_filter([
            '@type' => 'Collection',
            '@id'   => $this->identity?->uri($itemSet),
            'name'  => $name,
        ], static fn ($value) => $value !== null);
        $sameAs = $this->sameAsFor($itemSet);
        if ($sameAs) {
            $collection['sameAs'] = $sameAs;
        }
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            '@id'         => $url,
            'name'        => $name,
            'url'         => $url,
            'description' => $this->clip($description),
            'mainEntity'  => $collection,
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
                $affiliation = $this->firstLink($resource, 'dcterms:isPartOf', $site, 'Organization');
                if ($affiliation) {
                    $data['affiliation'] = $affiliation;
                }
                break;

            case 'Place':
                $lat = $this->firstString($resource, ['schema:latitude', 'geo:lat']);
                $lng = $this->firstString($resource, ['schema:longitude', 'geo:long']);
                if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
                    $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
                }
                $within = $this->firstLink($resource, 'dcterms:isPartOf', $site, 'Place');
                if ($within) {
                    $data['containedInPlace'] = $within;
                }
                break;

            case 'ResearchProject':
            case 'ResearchOrganization':
            case 'Organization':
                $parent = $this->firstLink($resource, 'dcterms:isPartOf', $site, 'Organization');
                if ($parent) {
                    $data['parentOrganization'] = $parent;
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

        // ISO 8601, as precise as stored; a date that names no year is left out.
        $published = $this->isoDate($resource, ['dcterms:issued', 'dcterms:date']);
        $created = $this->isoDate($resource, ['dcterms:created']);
        if ($type === 'VideoObject') {
            // Google requires uploadDate on a video: when it was published,
            // else made, else when the record was created.
            $upload = $published ?? $created ?? $this->createdAtom($resource);
            if ($upload !== null) {
                $data['uploadDate'] = $upload;
            }
            if ($published === null && $created !== null) {
                $data['dateCreated'] = $created;
            }
        } elseif ($published !== null) {
            $data['datePublished'] = $published;
        } elseif ($created !== null) {
            $data['dateCreated'] = $created;
        }

        // BCP 47 tags ("de"), not labels ("German").
        $languages = $this->languageCodes($resource);
        if ($languages) {
            $data['inLanguage'] = count($languages) === 1 ? $languages[0] : $languages;
        }

        $subjects = $this->labels($resource, 'dcterms:subject');
        if ($subjects) {
            $data['keywords'] = implode(', ', $subjects);
        }
        // keywords is text; the subject headings that are records of their own
        // are also named as nodes, by their resource URI.
        $about = array_values(array_filter(
            $this->nodes($resource, 'dcterms:subject', 'DefinedTerm'),
            static fn (array $node) => isset($node['@id'])
        ));
        if ($about) {
            $data['about'] = $about;
        }

        $places = $this->nodes($resource, 'dcterms:spatial', 'Place');
        if ($places) {
            $data['spatialCoverage'] = $places;
        }

        // The DOI identifies the published version; it is an identifier and
        // `sameAs`, never the record's own @id or url.
        $doi = $this->doi($resource);
        if ($doi !== null) {
            $data['identifier'] = [
                '@type'      => 'PropertyValue',
                'propertyID' => 'DOI',
                'value'      => $doi,
                'url'        => 'https://doi.org/' . $doi,
            ];
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

        $partOf = $this->firstLink($resource, 'dcterms:isPartOf', $site, null);
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
        // work; emit it whenever the item carries one. No default is asserted.
        $license = $this->licenseValue($resource);
        if ($license !== null) {
            $data['license'] = $license;
        }

        if ($this->isResearchItem($resource)) {
            $this->accessConditions($data, $resource);
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
     * The container: an article's periodical (nested in its volume and issue
     * when those are known), a chapter's book, a podcast episode's series; any
     * other work just isPartOf it. A linked container is typed as its own page
     * types it — a research item's project is a ResearchProject — and only a
     * container known by name alone takes the type its role implies.
     *
     * @param array<mixed> $data
     * @param array<string,mixed> $partOf a {@see firstLink()} node
     */
    private function containment(
        array &$data,
        string $type,
        array $partOf,
        AbstractResourceEntityRepresentation $resource,
        ?string $isbn
    ): void {
        $node = static fn (string $roleType, array $extra = []): array => array_filter(
            ['@type' => $partOf['@type'] ?? $roleType] + $partOf + $extra,
            static fn ($value) => $value !== null && $value !== ''
        );

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
        $data['includedInDataCatalog'] = array_filter(
            ['@type' => 'DataCatalog', 'name' => $site->title(), 'url' => SiteHome::url($site)],
            static fn ($value) => $value !== null
        );
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
     * The resource's licence ({@see Licence}): its URL when it resolves to one
     * — a licence registry URL on the linked licence item, or the canonical
     * creativecommons.org URL of a well-known label — else, for a linked
     * licence item, a CreativeWork named by its label under its resource URI,
     * else the literal text.
     *
     * @return string|array<string,string>|null
     */
    private function licenseValue(AbstractResourceEntityRepresentation $resource): string|array|null
    {
        $licence = Licence::of($resource);
        if ($licence === null) {
            return null;
        }
        if ($licence->url !== null) {
            return $licence->url;
        }
        if ($licence->item !== null) {
            return array_filter([
                '@type' => 'CreativeWork',
                '@id'   => $this->identity?->uri($licence->item),
                'name'  => $licence->name,
            ], static fn ($value) => $value !== null);
        }
        return $licence->name;
    }

    /**
     * A research item's conditions of access. AMIRA holds the description,
     * not the material: the access rights it records (dcterms:accessRights,
     * "Public", "Group", "Individual", "No Raw Data") are followed by the
     * statement that requests go to the project. `isAccessibleForFree` is set
     * only when every access right recorded decides it.
     *
     * @param array<mixed> $data
     */
    private function accessConditions(array &$data, AbstractResourceEntityRepresentation $resource): void
    {
        $rights = $this->labels($resource, 'dcterms:accessRights');
        $statement = $this->accessStatement();
        $conditions = array_values(array_filter([...$rights, $statement], static fn ($text) => $text !== null && $text !== ''));
        if ($conditions) {
            $data['conditionsOfAccess'] = count($conditions) === 1 ? $conditions[0] : $conditions;
        }

        $free = [];
        $map = array_change_key_case((array) ($this->options['access_free'] ?? []), CASE_LOWER);
        foreach ($rights as $right) {
            $key = mb_strtolower(trim($right));
            if (!array_key_exists($key, $map)) {
                return; // an access right that decides nothing: say nothing
            }
            $free[] = (bool) $map[$key];
        }
        $free = array_unique($free);
        if (count($free) === 1) {
            $data['isAccessibleForFree'] = $free[0];
        }
    }

    private function accessStatement(): ?string
    {
        if ($this->resolvedAccessStatement === null) {
            $statement = $this->options['access_statement'] ?? null;
            if ($statement instanceof \Closure) {
                try {
                    $statement = $statement();
                } catch (\Throwable $e) {
                    $statement = null;
                }
            }
            $this->resolvedAccessStatement = is_string($statement) ? trim($statement) : '';
        }
        return $this->resolvedAccessStatement !== '' ? $this->resolvedAccessStatement : null;
    }

    /** When the Omeka record was created, as an ISO 8601 timestamp. */
    private function createdAtom(AbstractResourceEntityRepresentation $resource): ?string
    {
        try {
            $created = method_exists($resource, 'created') ? $resource->created() : null;
        } catch (\Throwable $e) {
            return null;
        }
        return $created instanceof \DateTimeInterface ? $created->format(DATE_ATOM) : null;
    }

    /** A file size as people read it ("1.9 MB"). */
    private static function byteSize(int $bytes): string
    {
        foreach (['GB' => 1_000_000_000, 'MB' => 1_000_000, 'KB' => 1_000] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }
        return $bytes . ' B';
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
                $roleType = $isOrganization ? 'Organization' : $type;
                $node = $this->agent($label, $linked ? $this->linkedType($linked, $roleType) : $roleType);
                if ($linked) {
                    $node = $this->identified($node, $linked);
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

    /**
     * One node per distinct value of $term: a linked record by its title and
     * resource URI, a literal by its text.
     *
     * @return array<int,array<string,string>>
     */
    private function nodes(AbstractResourceEntityRepresentation $resource, string $term, string $type): array
    {
        $out = [];
        foreach ($resource->value($term, ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            $label = $linked ? trim((string) $linked->displayTitle()) : trim(strip_tags((string) $value));
            if ($label === '' || isset($out[$label])) {
                continue;
            }
            $node = ['@type' => $linked ? $this->linkedType($linked, $type) : $type, 'name' => $label];
            $out[$label] = $linked ? $this->identified($node, $linked) : $node;
        }
        return array_values($out);
    }

    /**
     * $node with the linked record's resource URI as its `@id`, right after
     * its `@type`, when DRE Linked Data publishes one.
     *
     * @param array<string,string> $node
     * @return array<string,string>
     */
    private function identified(array $node, AbstractResourceEntityRepresentation $linked): array
    {
        $uri = $this->identity?->uri($linked);
        if ($uri === null) {
            return $node;
        }
        return ['@type' => $node['@type'], '@id' => $uri] + $node;
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

    /**
     * The first value of $term as a node: a linked record typed as its own
     * page types it, with its resource URI and page; a literal by its name,
     * typed $roleType. A null $roleType leaves a literal untyped, for the
     * caller to type by role ({@see containment()}).
     *
     * @return array<string,string>|null
     */
    private function firstLink(
        AbstractResourceEntityRepresentation $resource,
        string $term,
        SiteRepresentation $site,
        ?string $roleType
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
            $linkedType = $this->mappedType($linked) ?? $roleType;
            return array_filter([
                '@type' => $linkedType,
                '@id'   => $this->identity?->uri($linked),
                'name'  => (string) $linked->displayTitle(),
                'url'   => $url,
            ], static fn ($value) => $value !== null && $value !== '');
        }
        $name = trim(strip_tags((string) $value));
        return $name !== '' ? array_filter(['@type' => $roleType, 'name' => $name], static fn ($value) => $value !== null) : null;
    }

    /**
     * The type a linked record is given: the type its own page declares, when
     * its template is mapped, else the type the role implies.
     */
    private function linkedType(AbstractResourceEntityRepresentation $linked, string $roleType): string
    {
        return $this->mappedType($linked) ?? $roleType;
    }

    /** The @type the template map gives a record, or null when its template is not mapped. */
    private function mappedType(AbstractResourceEntityRepresentation $resource): ?string
    {
        $templateId = $resource->resourceTemplate() ? $resource->resourceTemplate()->id() : null;
        return $templateId !== null && isset($this->templateTypes[$templateId]) ? $this->templateTypes[$templateId] : null;
    }

    /**
     * The home page's own URL ({@see SiteHome}), the same one its canonical,
     * the sitemap and IndexNow name. `/s/{slug}/` — with the slash — is a 404,
     * and the bare site root only redirects there.
     */
    private function homeUrl(PhpRenderer $view, SiteRepresentation $site): string
    {
        return SiteHome::url($site)
            ?? rtrim((string) $view->url('site', ['site-slug' => $site->slug()], ['force_canonical' => true]), '/');
    }
}
