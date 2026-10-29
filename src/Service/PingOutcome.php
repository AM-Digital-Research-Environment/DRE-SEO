<?php
declare(strict_types=1);

namespace DRESeo\Service;

/**
 * What became of one IndexNow submission, and so of the outbox rows it
 * carried.
 *
 * The three answers {@see Pinger::accepted()} and {@see Pinger::retryable()}
 * tell apart, as the outbox acts on them. An accepted batch is done. A
 * transient failure is worth sending again after a backoff. A refusal — a key
 * IndexNow could not verify, URLs that are not of the submitted host — would
 * fail the same way on every attempt, so its rows are parked at once where the
 * dashboard counts them as failed and `scripts/drain-indexnow.php
 * --retry-failed` re-queues them, which is the right thing to do only after
 * the cause is fixed. The settings-backed queue before the outbox dropped a
 * refused batch instead, and lost its URLs for good.
 */
enum PingOutcome: string
{
    /** IndexNow accepted the batch (2xx): the rows are done and deleted. */
    case Accepted = 'accepted';

    /**
     * No answer, a timeout, rate limiting or a server error: worth sending
     * again, after the outbox's backoff.
     */
    case Retry = 'retry';

    /**
     * Any other answer (400 bad request, 403 key not valid, 422 URLs not of
     * this host, an unfollowed 3xx): the same batch would fail the same way,
     * so the rows are marked exhausted rather than retried — kept, not
     * deleted, so they can be re-queued once the key or host is fixed.
     */
    case Refused = 'refused';

    /** Classify the HTTP status {@see Pinger::submitIndexNow()} returned. */
    public static function fromStatus(int $status): self
    {
        if (Pinger::accepted($status)) {
            return self::Accepted;
        }
        return Pinger::retryable($status) ? self::Retry : self::Refused;
    }
}
