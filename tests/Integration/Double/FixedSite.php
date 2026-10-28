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

    public function id()
    {
        return 1;
    }

    public function slug()
    {
        return 'amira';
    }

    public function title()
    {
        return 'AMIRA Smoke';
    }

    public function homepage()
    {
        return null;
    }
}
