<?php
declare(strict_types=1);

namespace DRESeo\Test\Double;

use DRESeo\Service\PingOutboxInterface;
use DRESeo\Service\PingOutcome;

/** Records what the queue asks of the outbox, for dispatch-policy tests. */
final class InMemoryPingOutbox implements PingOutboxInterface
{
    /** @var string[] */
    public array $pushed = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public ?int $lastLimit = null;

    /** @var array<int,array{rows:array<int,array<string,mixed>>,outcome:PingOutcome}> */
    public array $finished = [];

    /** How many due URLs have never been submitted, as countUnsent() reports it. */
    public int $unsent = 0;

    /** How often discardUnsent() was called. */
    public int $discards = 0;

    public function push(string $url): void
    {
        $this->pushed[] = $url;
    }

    public function claim(int $limit = 200): array
    {
        $this->lastLimit = $limit;
        return array_slice($this->rows, 0, $limit);
    }

    public function countUnsent(): int
    {
        return $this->unsent;
    }

    public function discardUnsent(): int
    {
        $this->discards++;
        $discarded = $this->unsent;
        $this->unsent = 0;
        return $discarded;
    }

    public function finish(array $rows, PingOutcome $outcome): void
    {
        $this->finished[] = ['rows' => $rows, 'outcome' => $outcome];
    }
}
