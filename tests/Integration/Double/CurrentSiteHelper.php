<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Laminas\View\Helper\AbstractHelper;

/** The `currentSite` view helper a public page has, answering a fixed site. */
final class CurrentSiteHelper extends AbstractHelper
{
    public function __construct(private readonly FixedSite $site)
    {
    }

    public function __invoke(): FixedSite
    {
        return $this->site;
    }
}
