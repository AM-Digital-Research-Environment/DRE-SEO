<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Omeka\Api\Representation\SiteRepresentation;

/** Site 1, slug `amira`, with no homepage: a real representation class, no database. */
final class FixedSite extends SiteRepresentation
{
    public function __construct()
    {
    }

    public function id(): int
    {
        return 1;
    }

    public function slug(): string
    {
        return 'amira';
    }

    public function title(): string
    {
        return 'AMIRA Smoke';
    }

    public function homepage(): ?object
    {
        return null;
    }
}
