<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Omeka\Api\Representation\SitePageRepresentation;

/** A site page with an id, a title and a slug: a real representation class, no database. */
final class FixedPage extends SitePageRepresentation
{
    public function __construct(
        private readonly int $pageId,
        private readonly string $pageTitle,
        private readonly string $pageSlug,
    ) {
    }

    public function id(): int
    {
        return $this->pageId;
    }

    public function title(): string
    {
        return $this->pageTitle;
    }

    public function slug(): string
    {
        return $this->pageSlug;
    }
}
