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
 *   ['citable' => false, …]   this IS a record the module knows, and it is a
 *                             descriptive one — a person, a place, a subject
 *                             heading, a journal. There is no citation because
 *                             there should not be one, which is a different
 *                             statement from "I cannot tell you", and the theme
 *                             titles the panel accordingly.
 *   ['citable' => true, …]    a citable work, with styles and downloads.
 *
 * The full shape:
 *   [
 *     'citable'      => bool,
 *     'record'       => <CitationRecord::toArray()>,   // citable only
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
        if (!$this->citationData->isCitable(CitationKindMap::templateId($item))) {
            return self::notCitable();
        }

        /** @var PhpRenderer $view */
        $view = $this->getView();
        $url = $this->itemUrl($view, $item);

        $record = $this->citationData->build($item, $url);
        if ($record === null) {
            return self::notCitable();
        }

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
            return self::notCitable();
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
            'citable'      => true,
            // The theme reads the record as an array; toArray() is that
            // published contract, and the only place the array form is built.
            'record'       => $record->toArray(),
            'defaultStyle' => $default,
            'styles'       => $styles,
            'downloads'    => $downloads,
        ];
    }

    /**
     * A descriptive record: the module has an opinion, and the opinion is that
     * there is nothing here to cite.
     *
     * @return array<string,mixed>
     */
    private static function notCitable(): array
    {
        return [
            'citable'      => false,
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

    private function itemUrl(PhpRenderer $view, ItemRepresentation $item): ?string
    {
        try {
            $site = $view->currentSite();
            if (!$site) {
                return null;
            }
            $url = (string) $item->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }

        return $url !== '' ? $url : null;
    }
}
