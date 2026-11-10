<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Laminas\View\Helper\AbstractHelper;

/**
 * A view helper answered by a closure: the stand-in for an Omeka helper
 * (assetUrl, pageTitle, url, translate) whose real one needs the application.
 */
final class CallbackHelper extends AbstractHelper
{
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->callback)(...$arguments);
    }
}
