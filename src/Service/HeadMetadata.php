<?php
declare(strict_types=1);

namespace DRESeo\Service;

use DRESeo\Service\Citation\Creator;
use DRESeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * Computes and injects every <head> SEO signal into Omeka's request-global head
 * placeholder helpers (headTitle / headMeta / headLink / headScript). The
 * theme echoes those helpers in <head>, so nothing here needs a theme edit.
 *
 * One shared instance lives for the whole request (registered shared in the
 * service manager). The Module's listeners call it in two phases:
 *
 *   1. During the action view render (view.show.after / view.browse.after) the
 *      resource- or page-specific signals are applied — these always win.
 *   2. During the layout render (view.layout, fired last) applyGlobals() adds
 *      the site-wide constants (og:site_name, verification tags, …) and
 *      gap-fills anything the first phase did not set.
 *
 * The $applied set records which signals phase 1 produced so phase 2 only fills
 * gaps and never clobbers a resource value.
 */
class HeadMetadata
{
    use ResourceValueReader;

    private const DESCRIPTION_MAX = 160;

    /** Open Graph locales for the languages a site is likely to be set to. */
    private const OG_LOCALES = [
        'en' => 'en_US', 'fr' => 'fr_FR', 'de' => 'de_DE', 'pt' => 'pt_BR',
        'es' => 'es_ES', 'sw' => 'sw_KE', 'ar' => 'ar_AR',
    ];

    /** The serialisations offered at /cite, as the media types Signposting announces. */
    private const DESCRIBED_BY = [
        'csljson' => 'application/vnd.citationstyles.csl+json',
        'bibtex'  => 'application/x-bibtex',
        'ris'     => 'application/x-research-info-systems',
    ];

    /** @var array<string,bool> Signals already emitted this request. */
    private array $applied = [];

    private ?string $description = null;
    private ?string $defaultImageUrl = null;
    private bool $defaultImageResolved = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly StructuredData $structuredData,
        private readonly CitationMeta $citationMeta,
        private readonly CitationKindMap $kinds,
        private readonly ResourceSummary $summary,
    ) {
    }

    // ─── Phase 1: resource pages ────────────────────────────────────────────

    public function applyResource(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        $title = $this->resourceTitle($resource);
        $fullDescription = $this->summary->describe($resource, $site->title());
        $description = $this->truncate($fullDescription);
        $canonical = $this->absoluteResourceUrl($resource, $site);
        $ownImage = $this->resourceImage($view, $resource);

        $this->setOpenGraph($view, [
            'og:type'  => $this->ogType($resource),
            'og:title' => $title,
            'og:url'   => $canonical,
        ]);
        $this->setDescription($view, $description);
        $this->setOpenGraph($view, ['og:description' => $description]);
        $this->ogTypeDetails($view, $resource);
        if ($canonical !== null) {
            $this->setCanonical($view, $canonical);
        }
        // A link preview may fall back to the site's share image; the
        // structured data may not, or it would say the logo depicts the record.
        $image = $ownImage ?? $this->resolveDefaultImage($view);
        if ($image !== null) {
            $this->setImage($view, $image, $title);
        }
        $this->markApplied('og:title');

        // A media page shows one file of an item, whose own page is the one to
        // index; the media page stays crawlable so its links are followed.
        if ($resource instanceof MediaRepresentation) {
            $this->setRobots($view, 'noindex, follow');
        }

        if ($this->jsonLdEnabled()) {
            $this->addJsonLd(
                $view,
                $this->structuredData->forResource($resource, $site, $canonical, $ownImage, $fullDescription)
            );
            $breadcrumb = $this->structuredData->breadcrumb($view, $resource, $site, $canonical);
            if ($breadcrumb !== null) {
                $this->addJsonLd($view, $breadcrumb);
            }
        }

        // Highwire Press + Dublin Core <meta> so Zotero / Google Scholar capture
        // the page as a reference, and FAIR Signposting links that point
        // machines at the same record in citation formats.
        if ($this->citationsEnabled()) {
            $this->citationMeta->apply($view, $resource, $canonical);
        }
        if ($canonical !== null && $resource instanceof ItemRepresentation) {
            $this->signposting($view, $resource, $canonical);
        }
    }

    // ─── Phase 1: static site pages ─────────────────────────────────────────

    /**
     * @param array{title?:string,description?:string,image?:int|string,robots?:string,jsonld?:bool} $overrides
     */
    public function applyPage(
        PhpRenderer $view,
        SitePageRepresentation $page,
        SiteRepresentation $site,
        array $overrides,
        bool $isHomepage
    ): void {
        // The home page is reachable at three URLs — the host root `/`, the site
        // root `/s/{slug}`, and this `/s/{slug}/page/{slug}` — which Google
        // consolidates onto the bare domain (it picks `/`, not the page URL, as
        // canonical). Declare `/` as the homepage canonical so our declaration
        // matches Google's choice; every other page stays self-canonical. The
        // sitemap lists the home at `/` to match.
        $canonical = $isHomepage
            ? rtrim($view->serverUrl('/'), '/') . '/'
            : $page->siteUrl($site->slug(), true);
        $this->setCanonical($view, $canonical);

        $title = isset($overrides['title']) && $overrides['title'] !== ''
            ? $overrides['title']
            : (string) $page->title();
        // Fully own the <title> when an editor set a custom one (clear the stack
        // first so the page's default title segment is replaced, not appended);
        // the theme appends the site + installation suffix afterwards.
        if (isset($overrides['title']) && $overrides['title'] !== '') {
            $view->headTitle()->getContainer()->exchangeArray([]);
            $view->headTitle()->append($overrides['title']);
        }

        $description = isset($overrides['description']) && $overrides['description'] !== ''
            ? $this->truncate($overrides['description'])
            : null;
        if ($description !== null) {
            $this->setDescription($view, $description);
        }

        $image = null;
        if (!empty($overrides['image'])) {
            $image = $this->assetUrl($view, (int) $overrides['image']);
        }
        if ($image !== null) {
            $this->setImage($view, $image, $title);
        }

        $this->setOpenGraph($view, array_filter([
            'og:type'        => 'website',
            'og:title'       => $title,
            'og:url'         => $canonical,
            'og:description' => $description,
        ], static fn ($v) => $v !== null && $v !== ''));
        $this->markApplied('og:title');

        if (!empty($overrides['robots'])) {
            $this->setRobots($view, (string) $overrides['robots']);
        }

        if ($isHomepage && $this->jsonLdEnabled()) {
            $this->addJsonLd($view, $this->structuredData->webSite($view, $site));
        }
    }

    // ─── Phase 1: browse / search listing pages ─────────────────────────────

    /**
     * A browse listing. An item set's landing page (/item-set/{id}) is a
     * browse of its items, and is where readers arrive from search, so it is
     * described by the item set: its title, description and image, and a
     * CollectionPage in JSON-LD.
     */
    public function applyBrowse(PhpRenderer $view, SiteRepresentation $site, ?ItemSetRepresentation $itemSet = null): void
    {
        // Self-referential canonical (full current URL) keeps facet/sort
        // variants from looking like duplicate content while staying safe for
        // paginated pages (no collapsing page 2 onto page 1).
        $current = $view->serverUrl(true);
        $this->setCanonical($view, $current);

        // Only noindex faceted / paginated / sorted variants (which carry a
        // query string). Clean landing pages stay indexable — crucially the
        // item-set pages (/item-set/{id}), which are listed in the sitemap;
        // marking them noindex made Search Console reject that sitemap.
        $hasQuery = ((string) (parse_url($current, PHP_URL_QUERY) ?? '')) !== '';
        if ($hasQuery && $this->boolSetting('dre_seo_noindex_browse')) {
            $this->setRobots($view, 'noindex, follow');
        }

        if ($itemSet === null) {
            return;
        }
        $title = (string) $itemSet->displayTitle();
        $description = $this->summary->describe($itemSet, $site->title());
        $this->setDescription($view, $this->truncate($description));
        $this->setOpenGraph($view, [
            'og:type'        => 'website',
            'og:title'       => $title,
            'og:url'         => $current,
            'og:description' => $this->truncate($description),
        ]);
        $image = $this->resourceImage($view, $itemSet);
        if ($image !== null) {
            $this->setImage($view, $image, $title);
        }
        if (!$hasQuery && $this->jsonLdEnabled()) {
            $this->addJsonLd($view, $this->structuredData->collectionPage($view, $itemSet, $site, $current, $description));
        }
    }

    // ─── Phase 2: site-wide constants + gap-fill (view.layout) ──────────────

    public function applyGlobals(PhpRenderer $view, ?SiteRepresentation $site): void
    {
        $headMeta = $view->headMeta();

        // Master noindex (staging) overrides everything.
        if ($this->boolSetting('dre_seo_noindex_site')) {
            $this->setRobots($view, 'noindex, nofollow', true);
        }
        // An indexable page lets Google show its image at full width in Search
        // and Discover, where the default is a small thumbnail.
        $this->setRobots($view, 'max-image-preview:large');

        // Verification tags — site-wide, on every public page.
        $gsc = $this->extractToken($this->stringSetting('dre_seo_gsc_verification'), 'google-site-verification');
        if ($gsc !== '') {
            $headMeta->appendName('google-site-verification', $gsc);
        }
        $bing = $this->extractToken($this->stringSetting('dre_seo_bing_verification'), 'msvalidate.01');
        if ($bing !== '') {
            $headMeta->appendName('msvalidate.01', $bing);
        }

        // Open Graph / Twitter constants.
        if ($site !== null) {
            $headMeta->setProperty('og:site_name', $site->title());
            $headMeta->setProperty('og:locale', $this->ogLocale($view));
        }
        $headMeta->appendName('twitter:card', 'summary_large_image');
        $twitter = trim($this->stringSetting('dre_seo_twitter_site'));
        if ($twitter !== '') {
            $headMeta->appendName('twitter:site', $twitter);
        }

        // Gap-fills (only when phase 1 set nothing).
        if (!$this->isApplied('description')) {
            $default = trim($this->stringSetting('dre_seo_default_description'));
            if ($default !== '') {
                $this->setDescription($view, $this->truncate($default));
            }
        }
        if (!$this->isApplied('image')) {
            $img = $this->resolveDefaultImage($view);
            if ($img !== null) {
                $this->setImage($view, $img, $site ? $site->title() : null);
            }
        }
        if (!$this->isApplied('canonical')) {
            $this->setCanonical($view, $view->serverUrl(true));
        }
        if (!$this->isApplied('og:title')) {
            // Mirror the rendered <title>'s leading segment as og/twitter title.
            $titleParts = $view->headTitle()->getContainer()->getArrayCopy();
            $title = $titleParts[0] ?? ($site ? $site->title() : '');
            if ($title !== '') {
                $this->setOpenGraph($view, ['og:title' => (string) $title, 'og:type' => 'website']);
            }
        }
        // Mirror description → og/twitter description if still missing.
        if ($this->description !== null && !$this->isApplied('og:description')) {
            $this->setOpenGraph($view, ['og:description' => $this->description]);
        }
        if (!$this->isApplied('og:url')) {
            $headMeta->setProperty('og:url', $view->serverUrl(true));
        }
    }

    // ─── Low-level setters (track what was applied) ─────────────────────────

    public function setDescription(PhpRenderer $view, string $description): void
    {
        $view->headMeta()->setName('description', $description);
        // Twitter reads og:description, but set the explicit one too for clarity.
        $view->headMeta()->setName('twitter:description', $description);
        $this->description = $description;
        $this->markApplied('description');
    }

    public function setCanonical(PhpRenderer $view, ?string $url): void
    {
        if ($url === null || $url === '' || $this->isApplied('canonical')) {
            return;
        }
        $view->headLink(['rel' => 'canonical', 'href' => $url]);
        $this->markApplied('canonical');
    }

    public function setImage(PhpRenderer $view, string $url, ?string $alt = null): void
    {
        $this->setOpenGraph($view, ['og:image' => $url]);
        $view->headMeta()->setName('twitter:image', $url);
        if ($alt !== null && trim($alt) !== '') {
            $view->headMeta()->setProperty('og:image:alt', $alt);
            $view->headMeta()->setName('twitter:image:alt', $alt);
        }
        $this->markApplied('image');
    }

    public function setRobots(PhpRenderer $view, string $value, bool $force = false): void
    {
        if (!$force && $this->isApplied('robots')) {
            return;
        }
        $view->headMeta()->setName('robots', $value);
        $this->markApplied('robots');
    }

    /** @param array<string,string|null> $tags og property => content */
    public function setOpenGraph(PhpRenderer $view, array $tags): void
    {
        $headMeta = $view->headMeta();
        foreach ($tags as $property => $content) {
            if ($content === '' || $content === null) {
                continue;
            }
            $headMeta->setProperty($property, $content);
            // Twitter falls back to og:* for most fields; mirror title only.
            if ($property === 'og:title') {
                $headMeta->setName('twitter:title', $content);
            }
            $this->markApplied($property);
        }
    }

    /** @param array<mixed> $data A JSON-LD document. */
    public function addJsonLd(PhpRenderer $view, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return;
        }
        // Emit the document as raw JSON. The `noescape` attribute suppresses the
        // `//<!-- … //-->` comment guard HeadScript wraps every inline script
        // body in — that guard is invalid inside application/ld+json. (Laminas'
        // setAutoEscape() governs only attribute/type escaping, not the guard,
        // so the previous call left the wrapper in place.) JSON_HEX_TAG already
        // escapes `<`/`>`, so a `</script>` breakout is impossible without it.
        $view->headScript()->appendScript($json, 'application/ld+json', ['noescape' => true]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * The page title. A media record is often titled with its source URL
     * ("https://epub.uni-bayreuth.de/…/file.pdf"); its item's title says more.
     */
    private function resourceTitle(AbstractResourceEntityRepresentation $resource): string
    {
        $title = trim((string) $resource->displayTitle());
        if ($resource instanceof MediaRepresentation
            && ($title === '' || preg_match('#\Ahttps?://#i', $title))
        ) {
            try {
                return trim((string) $resource->item()->displayTitle()) ?: $title;
            } catch (\Throwable $e) {
                return $title;
            }
        }
        return $title;
    }

    /**
     * Open Graph type: a person's page is a profile, a book a book, a video a
     * video; any other work is an article, and a descriptive record, an item
     * set or a media page is a website.
     */
    private function ogType(AbstractResourceEntityRepresentation $resource): string
    {
        if (!$resource instanceof ItemRepresentation) {
            return 'website';
        }
        return match ($this->kinds->forResource($resource)) {
            CitationKind::Person => 'profile',
            CitationKind::Book => 'book',
            CitationKind::Video => 'video.other',
            CitationKind::Place, CitationKind::Organization, CitationKind::Project,
            CitationKind::Section, CitationKind::Authority, CitationKind::Journal => 'website',
            default => 'article',
        };
    }

    /** The properties each Open Graph type defines, where the record has them. */
    private function ogTypeDetails(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): void
    {
        if (!$resource instanceof ItemRepresentation) {
            return;
        }
        $type = $this->ogType($resource);
        $tags = [];
        if ($type === 'profile') {
            $creator = Creator::parse((string) $resource->displayTitle(), false);
            if ($creator->given !== null) {
                $tags['profile:first_name'] = $creator->given;
                $tags['profile:last_name'] = (string) $creator->family;
            }
        } elseif ($type === 'book') {
            $tags['book:isbn'] = $this->isbn($resource);
        } elseif ($type === 'article') {
            try {
                $modified = $resource->modified();
            } catch (\Throwable $e) {
                $modified = null;
            }
            if ($modified instanceof \DateTimeInterface) {
                $tags['article:modified_time'] = $modified->format(DATE_ATOM);
            }
        }
        foreach ($tags as $property => $content) {
            if ($content !== null && $content !== '') {
                $view->headMeta()->setProperty($property, $content);
            }
        }
    }

    /**
     * The resource's own image: a thumbnail chosen for it, else the large
     * derivative of its primary media. Omeka gives a media with no derivative
     * a generic icon (an audio or a PDF symbol) as its thumbnail URL; that is
     * no picture of the record, so it is not used. Null when there is none.
     */
    private function resourceImage(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): ?string
    {
        try {
            $asset = $resource->thumbnail();
            if ($asset) {
                return $this->absolutize($view, $asset->assetUrl());
            }
        } catch (\Throwable $e) {
            // no chosen thumbnail
        }

        $media = null;
        if ($resource instanceof ItemRepresentation) {
            $media = $resource->primaryMedia();
        } elseif ($resource instanceof MediaRepresentation) {
            $media = $resource;
        }
        if ($media instanceof MediaRepresentation && $media->hasThumbnails()) {
            $thumb = $media->thumbnailUrl('large');
            if ($thumb) {
                return $this->absolutize($view, $thumb);
            }
        }
        return null;
    }

    /**
     * FAIR Signposting (signposting.org/FAIR, level 1) as HTML <link> elements:
     * the identifier to cite, the machine-readable descriptions of the record,
     * what kind of thing the page is about, its licence and its public files.
     * F-UJI and other FAIR assessors read these; search engines ignore them.
     */
    private function signposting(PhpRenderer $view, ItemRepresentation $item, string $canonical): void
    {
        $doi = $this->doi($item);
        $links = [['rel' => 'cite-as', 'href' => $doi !== null ? 'https://doi.org/' . $doi : $canonical]];

        if ($this->citationsEnabled()) {
            foreach (self::DESCRIBED_BY as $format => $mediaType) {
                $links[] = [
                    'rel'  => 'describedby',
                    'href' => $view->serverUrl('/cite/' . $item->id() . '/' . $format),
                    'type' => $mediaType,
                ];
            }
        }
        try {
            $links[] = ['rel' => 'describedby', 'href' => $item->apiUrl(), 'type' => 'application/ld+json'];
        } catch (\Throwable $e) {
            // no API URL
        }

        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/' . $this->structuredData->typeFor($item)];
        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/AboutPage'];

        $license = $this->uris($item, 'dcterms:license')[0] ?? null;
        if ($license !== null) {
            $links[] = ['rel' => 'license', 'href' => $license];
        }

        $files = 0;
        foreach ($item->media() as $media) {
            if ($files >= 10 || (method_exists($media, 'isPublic') && !$media->isPublic())) {
                continue;
            }
            $url = method_exists($media, 'originalUrl') ? $media->originalUrl() : null;
            $type = method_exists($media, 'mediaType') ? $media->mediaType() : null;
            if ($url && $type) {
                $links[] = ['rel' => 'item', 'href' => $url, 'type' => $type];
                $files++;
            }
        }

        foreach ($links as $link) {
            $view->headLink($link);
        }
    }

    /**
     * Omeka file/asset URLs are already absolute (scheme + host); leave those
     * untouched and only prepend the host to a root-relative path.
     */
    private function absolutize(PhpRenderer $view, string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $view->serverUrl($url);
    }

    private function absoluteResourceUrl(
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): ?string {
        try {
            return $resource->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveDefaultImage(PhpRenderer $view): ?string
    {
        if ($this->defaultImageResolved) {
            return $this->defaultImageUrl;
        }
        $this->defaultImageResolved = true;
        $assetId = (int) $this->stringSetting('dre_seo_default_share_image');
        if ($assetId > 0) {
            $this->defaultImageUrl = $this->assetUrl($view, $assetId);
        }
        return $this->defaultImageUrl;
    }

    private function assetUrl(PhpRenderer $view, int $assetId): ?string
    {
        if ($assetId <= 0) {
            return null;
        }
        try {
            $asset = $view->api()->read('assets', $assetId)->getContent();
            return $this->absolutize($view, $asset->assetUrl());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Accept either a full <meta …> snippet pasted from the search console or a
     * bare token, and return just the token.
     */
    private function extractToken(string $raw, string $metaName): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, '<meta') !== false
            && preg_match('/content\s*=\s*"([^"]+)"/i', $raw, $m)
        ) {
            return trim($m[1]);
        }
        // Strip accidental surrounding quotes/markup.
        return trim(strip_tags($raw), " \t\n\r\0\x0B\"'");
    }

    private function truncate(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " ,.;:") . '…';
    }

    /**
     * og:locale is language_TERRITORY ("en_US"). The page language comes from
     * ViewLocale — `lang` is a view helper, so a method_exists() guard never
     * finds it, which is how every page used to say "en".
     */
    private function ogLocale(PhpRenderer $view): string
    {
        $lang = str_replace('-', '_', ViewLocale::resolve($view));
        if (str_contains($lang, '_')) {
            [$language, $territory] = explode('_', $lang, 2);
            return strtolower($language) . '_' . strtoupper($territory);
        }
        return self::OG_LOCALES[strtolower($lang)] ?? strtolower($lang);
    }

    private function jsonLdEnabled(): bool
    {
        return $this->boolSetting('dre_seo_jsonld_enabled', true);
    }

    private function citationsEnabled(): bool
    {
        return $this->boolSetting('dre_seo_citation_meta', true);
    }

    private function boolSetting(string $key, bool $default = false): bool
    {
        return SettingFlag::isOn($this->settings, $key, $default);
    }

    private function stringSetting(string $key): string
    {
        return (string) ($this->settings->get($key, '') ?? '');
    }

    private function markApplied(string $key): void
    {
        $this->applied[$key] = true;
    }

    private function isApplied(string $key): bool
    {
        return !empty($this->applied[$key]);
    }
}
