<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * Where changed URLs wait for IndexNow: pushed on save, leased in batches by
 * the job, then acknowledged, released for retry or parked as refused.
 * {@see PingRepository} is the durable table; the interface keeps
 * {@see PingQueue}'s dispatch policy testable without a database.
 */
interface PingOutboxInterface
{
    /** Queue a URL, or bump an already queued one so a newer edit survives. */
    public function push(string $url): void;

    /**
     * Lease up to $limit due URLs.
     *
     * @return array<int,array<string,mixed>> the leased rows
     */
    public function claim(int $limit = 200): array;

    /**
     * How many due URLs no drain has submitted since they were queued: what
     * the bulk-change guard weighs. A URL being retried, or re-queued after
     * failing, was submitted before and is not counted.
     */
    public function countUnsent(): int;

    /**
     * Acknowledge every due, never-submitted URL without sending it — a bulk
     * change the sitemap announces instead.
     *
     * @return int how many URLs were removed
     */
    public function discardUnsent(): int;

    /**
     * Settle leased rows by what IndexNow made of them: delete accepted rows,
     * release retryable ones for a later attempt, and mark refused ones
     * exhausted, so they are counted as failed and wait for an explicit
     * re-queue instead of being resent.
     *
     * @param array<int,array<string,mixed>> $rows rows returned by claim()
     */
    public function finish(array $rows, PingOutcome $outcome): void;
}
