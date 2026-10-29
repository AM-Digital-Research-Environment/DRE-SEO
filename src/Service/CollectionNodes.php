<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * The schema.org nodes for collections of records, as {@see StructuredData}
 * publishes them: the whole catalogue as a Dataset (for Google Dataset
 * Search), an item set's landing page as a CollectionPage, and a Dataset
 * record's public files as its DataDownload distribution. Stateless; what
 * names a record (its resource URI, its sameAs IRIs) is StructuredData's to
 * work out and is handed in.
 */
final class CollectionNodes
{
    use ResourceValueReader;

    /** Google Dataset Search ignores a Dataset whose description is shorter. */
    public const DATASET_DESCRIPTION_MIN = 50;

    /**
     * The whole catalogue as a schema.org Dataset, from DRE Linked Data's
     * description of its dataset (VoID, licence, publisher, dumps). Null when
     * there is nothing to describe, or no description of the length Google
     * requires can be given.
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
        $home = SiteHome::absoluteUrl($view, $site);
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

    /**
     * An item set's landing page: a CollectionPage about the set.
     *
     * @param string|null $uri    the item set's resource URI, the @id DRE
     *                            Linked Data describes it under
     * @param string[]    $sameAs the IRIs the item set is the same as
     * @return array<mixed>
     */
    public function collectionPage(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $itemSet,
        SiteRepresentation $site,
        string $url,
        string $description,
        ?string $uri = null,
        array $sameAs = []
    ): array {
        $name = (string) $itemSet->displayTitle();
        // The page is about the item set, which has an identity of its own.
        $collection = array_filter([
            '@type' => 'Collection',
            '@id'   => $uri,
            'name'  => $name,
        ], static fn ($value) => $value !== null);
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
            'isPartOf'    => ['@type' => 'WebSite', 'name' => $site->title(), 'url' => SiteHome::absoluteUrl($view, $site)],
        ];
    }

    /**
     * A Dataset record's public files as DataDownload distributions, and the
     * AMIRA site as the catalogue it is listed in.
     *
     * @param array<mixed> $data the record's node, completed in place
     */
    public function distribution(
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

    /** An absolute http(s) URL, or null. */
    private static function httpUrl(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        return preg_match('~^https?://~i', $url) ? $url : null;
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
}
