<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The durable IndexNow outbox: one row per changed URL, in a table of its
 * own (`dre_seo_ping`).
 *
 * It replaces a queue kept in two rows of Omeka's `setting` table, which had
 * to be read and written with raw SQL under a MySQL named lock to survive two
 * saves at once, and which a dead worker could leave "queued" for fifteen
 * minutes. Here a URL is a row keyed by its hash, so two saves of different
 * records never touch the same row and two saves of the same record collapse
 * into one. A job leases a batch for ten minutes rather than taking it, so a
 * worker that dies mid-submission loses nothing: the lease runs out and the
 * next job sends the rows again.
 *
 * Versioned leases make acknowledgement safe during concurrent edits: every
 * push bumps the row's version, and a job settles only the version it
 * leased, so an edit made while its batch was in flight is never acknowledged
 * by the older submission. Retryable failures back off exponentially (two
 * minutes, doubling, at most a day) until MAX_ATTEMPTS; refusals go straight
 * to it.
 *
 * Plain SQL that runs on MySQL/MariaDB, as production does, and on SQLite, as
 * the tests do.
 */
final class PingRepository implements PingOutboxInterface
{
    public const TABLE = 'dre_seo_ping';

    /**
     * Attempts after which a URL stops being sent: it is "failed" on the
     * dashboard until retryFailed() re-queues it. Retryable failures reach it
     * through backoff; a refusal is set to it at once.
     */
    public const MAX_ATTEMPTS = 5;

    /** How long (seconds) a job holds the rows it leased. */
    private const LEASE = 600;

    /** The most rows one claim leases. */
    private const MAX_CLAIM = 200;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Create the table and its index; safe to run again (install, upgrade). */
    public function install(): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS dre_seo_ping (
            id CHAR(64) PRIMARY KEY, url TEXT NOT NULL, version INTEGER NOT NULL,
            token CHAR(32) NOT NULL, lease_until BIGINT NOT NULL, available_at BIGINT NOT NULL,
            attempts INTEGER NOT NULL)');
        if (!isset($this->connection->getSchemaManager()->listTableIndexes(self::TABLE)['dre_seo_ping_due'])) {
            $this->connection->executeStatement('CREATE INDEX dre_seo_ping_due ON dre_seo_ping (available_at, lease_until, attempts)');
        }
    }

    /** Drop the table, and every URL still in it (uninstall). */
    public function uninstall(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS dre_seo_ping');
    }

    public function push(string $url): void
    {
        if (!self::isAbsoluteUrl($url)) {
            return;
        }
        $id = hash('sha256', $url);
        try {
            $this->connection->insert(self::TABLE, ['id' => $id, 'url' => $url, 'version' => 1,
                'token' => '', 'lease_until' => 0, 'available_at' => time(), 'attempts' => 0]);
        } catch (UniqueConstraintViolationException $e) {
            // Queued already: a fresh start for the newer edit, which also
            // takes the row from under any lease of the older one.
            $this->connection->executeStatement(
                'UPDATE dre_seo_ping SET version = version + 1,
                token = :token, lease_until = 0, available_at = :now, attempts = 0 WHERE id = :id',
                ['token' => '', 'now' => time(), 'id' => $id]
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function claim(int $limit = self::MAX_CLAIM, ?int $now = null): array
    {
        $now ??= time();
        // LIMIT is inlined as an already-clamped integer: PDO refuses a bound
        // parameter there under emulated prepares.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM dre_seo_ping
            WHERE available_at <= :now AND lease_until <= :now AND attempts < :cap
            ORDER BY available_at, id LIMIT ' . max(1, min($limit, self::MAX_CLAIM)),
            ['now' => $now, 'cap' => self::MAX_ATTEMPTS]
        );
        $claimed = [];
        foreach ($rows as $row) {
            // Leased only if no other job leased it, and no edit bumped it,
            // since it was read.
            $token = bin2hex(random_bytes(16));
            $changed = $this->connection->executeStatement(
                'UPDATE dre_seo_ping SET token = :token,
                lease_until = :lease WHERE id = :id AND version = :version AND lease_until <= :now',
                ['token' => $token, 'lease' => $now + self::LEASE, 'id' => $row['id'], 'version' => $row['version'], 'now' => $now]
            );
            if ($changed === 1) {
                $row['token'] = $token;
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    /**
     * Every write is conditioned on the lease's token and the row's version,
     * so a URL edited again while its batch was in flight keeps the newer
     * edit's fresh state whatever the outcome of the older submission.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function finish(array $rows, PingOutcome $outcome, ?int $now = null): void
    {
        $now ??= time();
        foreach ($rows as $row) {
            $where = ['id' => $row['id'], 'token' => $row['token'], 'version' => $row['version']];
            if ($outcome === PingOutcome::Accepted) {
                $this->connection->delete(self::TABLE, $where);
                continue;
            }
            $attempts = $outcome === PingOutcome::Refused
                ? self::MAX_ATTEMPTS
                : (int) $row['attempts'] + 1;
            $this->connection->update(self::TABLE, ['token' => '', 'lease_until' => 0,
                'attempts' => $attempts, 'available_at' => $now + min(86400, 60 * (2 ** $attempts))], $where);
        }
    }

    /** Whether any URL is due for submission now (not leased, not backing off, not exhausted). */
    public function hasDue(?int $now = null): bool
    {
        $now ??= time();
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM dre_seo_ping WHERE available_at <= :now AND lease_until <= :now AND attempts < :cap LIMIT 1',
            ['now' => $now, 'cap' => self::MAX_ATTEMPTS]
        );
    }

    /**
     * `failed` covers both ways a URL stops being sent: retries exhausted, and
     * a submission IndexNow refused.
     *
     * @return array{pending:int,failed:int}
     */
    public function counts(): array
    {
        $cap = ['cap' => self::MAX_ATTEMPTS];
        return [
            'pending' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dre_seo_ping WHERE attempts < :cap', $cap),
            'failed' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dre_seo_ping WHERE attempts >= :cap', $cap),
        ];
    }

    /** Re-queue every failed URL — after fixing whatever made it fail. */
    public function retryFailed(): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_seo_ping SET attempts = 0, available_at = 0 WHERE attempts >= :cap',
            ['cap' => self::MAX_ATTEMPTS]
        );
    }

    /**
     * An absolute http(s) URL with a host: the only kind IndexNow takes, and
     * the only kind the job can group by origin.
     */
    private static function isAbsoluteUrl(string $url): bool
    {
        return preg_match('~\Ahttps?://[^\s<>"{}]+\z~i', $url) === 1
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
