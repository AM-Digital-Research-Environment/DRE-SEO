<?php
declare(strict_types=1);

namespace DRESeo\View\Helper;

use DRESeo\Service\CitationData;
use DRESeo\Service\CitationExport;
use DRESeo\Service\CitationFormatter;
use DRESeo\Service\CitationKindMap;
use DRESeo\Service\ViewLocale;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * dreCitation($item) — the view-model the theme renders in the record page's
 * "Cite this record" rail. The mapping and formatting live here (DRE-SEO owns
 * the citation contract, so the panel and the Zotero/Scholar meta tags cannot
 * drift apart); the theme owns the markup and styling.
 *
 * Three answers, and the theme needs all three kept apart:
 *
 *   null                      no opinion — the feature is off, or (as the theme
 *                             sees it) the module is not installed at all. The
 *                             theme keeps its own baseline.
 *   ['citable' => false,      a descriptive record — a person, a place, a
 *    'citeAs' => 'entry', …]  subject heading, a journal. It is not a work, so
 *                             no Highwire tags claim it is one, but it can be
 *                             cited as what it is: an entry of the AMIRA
 *                             database, with its type, the database, the
 *                             publisher and the date the record last changed.
 *   ['citable' => true,       a citable work, with styles and downloads.
 *    'citeAs' => 'work', …]
 *
 * `citeAs` was added in 0.11.0; a theme that predates it reads `citable` alone
 * and shows the entry citation under its "This record" heading.
 *
 * The full shape:
 *   [
 *     'citable'      => bool,
 *     'citeAs'       => 'work' | 'entry',
 *     'record'       => <CitationRecord::toArray()>,
 *     'defaultStyle' => 'curated' | 'chicago',
 *     'styles'       => ['curated' => ['label'=>…, 'html'=>…], 'chicago'=>…, 'apa'=>…, 'mla'=>…],
 *     'downloads'    => ['bibtex' => ['url'=>…, 'label'=>…, 'ext'=>…], 'ris'=>…, …],
 *   ]
 *
 * A hand-entered dcterms:bibliographicCitation is offered first, as the
 * `curated` pseudo-style, and becomes the default: a curator who wrote a
 * citation for a record meant it to be the one people copy. It is a *style*
 * rather than a separate field so the theme's switcher and copy button need no
 * special case — and because it is not a generated serialisation, the /cite
 * downloads never see it.
 */
class Citation extends AbstractHelper
{
    private const FORMAT_LABELS = [
        'bibtex'  => 'BibTeX',
        'ris'     => 'RIS',
        'csljson' => 'CSL JSON',
    ];

    /** The style id under which a hand-entered citation is offered. */
    public const CURATED_STYLE = 'curated';

    /**
     * @param array<string,string> $styleLabels  style id => display label (ordered)
     * @param string[]             $enabledFormats
     */
    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationFormatter $formatter,
        private readonly string $defaultStyle,
        private readonly array $styleLabels,
        private readonly array $enabledFormats,
        private readonly bool $enabled,
        private readonly string $curatedLabel = 'As catalogued',
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function __invoke(ItemRepresentation $item): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $isWork = $this->citationData->isCitable(CitationKindMap::templateId($item));

        /** @var PhpRenderer $view */
        $view = $this->getView();
        $site = $this->currentSite($view);
        $url = $this->itemUrl($item, $site);
        $record = $this->citationData->build($item, $url, $site?->title());

        $locale = ViewLocale::forCitation($view);

        $styles = [];
        $curated = $this->curated($item);
        if ($curated !== null) {
            $styles[self::CURATED_STYLE] = [
                'label' => $this->curatedLabel,
                'html'  => htmlspecialchars($curated, ENT_QUOTES, 'UTF-8'),
            ];
        }
        foreach ($this->styleLabels as $id => $label) {
            $styles[(string) $id] = [
                'label' => $label,
                'html'  => $this->formatter->format($record, (string) $id, $locale),
            ];
        }
        if (!$styles) {
            return self::notCitable($isWork);
        }

        $downloads = [];
        foreach ($this->enabledFormats as $fmt) {
            if (!isset(CitationExport::FORMATS[$fmt])) {
                continue;
            }
            $downloads[$fmt] = [
                'url'   => $view->serverUrl('/cite/' . $record->id . '/' . $fmt),
                'label' => self::FORMAT_LABELS[$fmt] ?? strtoupper($fmt),
                'ext'   => CitationExport::FORMATS[$fmt][0],
            ];
        }

        $default = isset($styles[self::CURATED_STYLE])
            ? self::CURATED_STYLE
            : (isset($styles[$this->defaultStyle]) ? $this->defaultStyle : (string) array_key_first($styles));

        return [
            'citable'      => $isWork,
            'citeAs'       => $isWork ? 'work' : 'entry',
            // The theme reads the record as an array; toArray() is that
            // published contract, and the only place the array form is built.
            'record'       => $record->toArray(),
            'defaultStyle' => $default,
            'styles'       => $styles,
            'downloads'    => $downloads,
        ];
    }

    /**
     * Nothing to offer: no style is configured. The module still has an
     * opinion on whether the record is a work.
     *
     * @return array<string,mixed>
     */
    private static function notCitable(bool $isWork): array
    {
        return [
            'citable'      => $isWork,
            'citeAs'       => $isWork ? 'work' : 'entry',
            'record'       => null,
            'defaultStyle' => null,
            'styles'       => [],
            'downloads'    => [],
        ];
    }

    /** The curator's own citation for this record, if one was entered. */
    private function curated(ItemRepresentation $item): ?string
    {
        try {
            $value = $item->value('dcterms:bibliographicCitation');
        } catch (\Throwable $e) {
            return null;
        }
        if ($value === null) {
            return null;
        }
        $text = trim(strip_tags((string) $value));

        return $text !== '' ? $text : null;
    }

    private function currentSite(PhpRenderer $view): ?SiteRepresentation
    {
        try {
            $site = $view->currentSite();
        } catch (\Throwable $e) {
            return null;
        }
        return $site instanceof SiteRepresentation ? $site : null;
    }

    private function itemUrl(ItemRepresentation $item, ?SiteRepresentation $site): ?string
    {
        if ($site === null) {
            return null;
        }
        try {
            $url = (string) $item->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }

        return $url !== '' ? $url : null;
    }
}
