<?php
declare(strict_types=1);

namespace DRESeo\Test\Integration\Double;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * An item with a title and nothing else: a real representation class, no
 * database. Public or private, it carries no template (so it cites as a
 * generic work), no values and no media.
 */
final class FixedItem extends ItemRepresentation
{
    public function __construct(
        private readonly int $itemId,
        private readonly string $itemTitle,
        private readonly bool $public = true,
        private readonly ?\DateTime $modifiedAt = null,
    ) {
    }

    public function id(): int
    {
        return $this->itemId;
    }

    public function displayTitle(mixed $default = null, mixed $lang = null): string
    {
        return $this->itemTitle;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /** @param array<string,mixed> $options */
    public function value(mixed $term, array $options = []): mixed
    {
        return ($options['all'] ?? false) ? [] : null;
    }

    public function resourceTemplate(): ?object
    {
        return null;
    }

    public function resourceClass(): ?object
    {
        return null;
    }

    /** @return array<int,mixed> */
    public function media(): array
    {
        return [];
    }

    public function modified(): ?\DateTime
    {
        return $this->modifiedAt;
    }

    public function created(): ?\DateTime
    {
        return $this->modifiedAt;
    }

    public function siteUrl(mixed $siteSlug = null, mixed $canonical = false): string
    {
        return 'https://example.test/s/' . $siteSlug . '/item/' . $this->itemId;
    }
}
