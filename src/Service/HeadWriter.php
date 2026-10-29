<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;

/**
 * Writes `<head>` signals into Omeka's request-global placeholder helpers
 * (headTitle / headMeta / headLink / headScript), and remembers which ones
 * have been written.
 *
 * The module emits head metadata in two passes — resource and page specifics
 * during the action render, site-wide constants and gap-fills during the
 * layout render — so "has this signal already been set?" has to be answered
 * across the two. That answer is this object's reason to exist: it holds the
 * module's request-scoped record of what the head says (the applied set, the
 * description written, the Signposting links for the HTTP Link header), and
 * keeping it in one small class with a documented lifetime is what lets
 * {@see HeadMetadata} be policy about *what* to write. Ported from IWAC SEO;
 * the writes themselves are DRE's, unchanged.
 *
 * Lifetime: one instance per request (registered shared). Anything longer —
 * a queue worker reusing the container across jobs — would leak one page's
 * applied set into the next, which is why nothing here is static.
 */
final class HeadWriter
{
    /** @var array<string,bool> Signals already emitted this request. */
    private array $applied = [];

    /** The description as written, so the layout pass can mirror it to og:description. */
    private ?string $description = null;

    /** @var array<int,array{rel:string,href:string,type?:string}> The page's Signposting links. */
    private array $signposts = [];

    public function description(PhpRenderer $view, string $description): void
    {
        $view->headMeta()->setName('description', $description);
        // Twitter reads og:description, but set the explicit one too for clarity.
        $view->headMeta()->setName('twitter:description', $description);
        $this->description = $description;
        $this->mark('description');
    }

    /** The description written this request, if any. */
    public function writtenDescription(): ?string
    {
        return $this->description;
    }

    /** The page's canonical link; the first one written stands. */
    public function canonical(PhpRenderer $view, ?string $url): void
    {
        if ($url === null || $url === '' || $this->has('canonical')) {
            return;
        }
        $view->headLink(['rel' => 'canonical', 'href' => $url]);
        $this->mark('canonical');
    }

    /**
     * The share-card image, with its alt text and, when it is known, its
     * pixel size, so a preview can be laid out before the image loads.
     *
     * @param array{0:int,1:int}|null $size width and height; left out when unknown
     */
    public function image(PhpRenderer $view, string $url, ?string $alt = null, ?array $size = null): void
    {
        $this->openGraph($view, ['og:image' => $url]);
        if ($size !== null) {
            $view->headMeta()->setProperty('og:image:width', (string) $size[0]);
            $view->headMeta()->setProperty('og:image:height', (string) $size[1]);
        }
        $view->headMeta()->setName('twitter:image', $url);
        if ($alt !== null && trim($alt) !== '') {
            $view->headMeta()->setProperty('og:image:alt', $alt);
            $view->headMeta()->setName('twitter:image:alt', $alt);
        }
        $this->mark('image');
    }

    /**
     * The robots meta. The first directive written stands — a record's own
     * noindex wins over the layout pass's — unless $force: the site-wide
     * staging noindex outranks any per-page directive.
     */
    public function robots(PhpRenderer $view, string $value, bool $force = false): void
    {
        if (!$force && $this->has('robots')) {
            return;
        }
        $view->headMeta()->setName('robots', $value);
        $this->mark('robots');
    }

    /** @param array<string,string|null> $tags og property => content */
    public function openGraph(PhpRenderer $view, array $tags): void
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
            $this->mark($property);
        }
    }

    /**
     * A `<meta property>` that is no signal the passes negotiate — a
     * site-wide constant, or a detail of the page's Open Graph type — so it is
     * written as it comes, and not recorded.
     */
    public function property(PhpRenderer $view, string $property, string $content): void
    {
        $view->headMeta()->setProperty($property, $content);
    }

    /** A `<meta name>` added beside any of the same name: verification tokens, the Twitter card. */
    public function appendName(PhpRenderer $view, string $name, string $content): void
    {
        $view->headMeta()->appendName($name, $content);
    }

    /** @param array<mixed> $data A JSON-LD document. */
    public function jsonLd(PhpRenderer $view, array $data): void
    {
        // Compact: nobody reads the inline block, and on a record with a long
        // abstract the indentation is a measurable share of the page.
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
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

    /**
     * The record's FAIR Signposting links, as `<link>` elements, kept for the
     * Module to repeat as an HTTP `Link` header once the page is rendered.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    public function signposts(PhpRenderer $view, array $links): void
    {
        $this->signposts = $links;
        foreach ($links as $link) {
            $view->headLink($link);
        }
    }

    /**
     * The Signposting links written this request; empty on a page that has
     * none (a static page, a listing).
     *
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    public function writtenSignposts(): array
    {
        return $this->signposts;
    }

    /** Replace the title stack with a single segment (an editor's override). */
    public function title(PhpRenderer $view, string $title): void
    {
        $view->headTitle()->getContainer()->exchangeArray([]);
        $view->headTitle()->append($title);
        $this->mark('title');
    }

    /** The leading segment of the rendered `<title>`, if the theme set one (it may be ''). */
    public function leadingTitle(PhpRenderer $view): ?string
    {
        $parts = $view->headTitle()->getContainer()->getArrayCopy();
        return isset($parts[0]) ? (string) $parts[0] : null;
    }

    public function has(string $signal): bool
    {
        return !empty($this->applied[$signal]);
    }

    public function mark(string $signal): void
    {
        $this->applied[$signal] = true;
    }
}
