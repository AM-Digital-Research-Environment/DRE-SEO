<?php
declare(strict_types=1);

namespace DRESeo\Test\Double;

use Laminas\Log\LoggerInterface;

/** Keeps every log record by level, so a test can assert what was logged. */
final class MemoryLogger implements LoggerInterface
{
    /** @var array<string,array<int,array{message:string,extra:mixed}>> */
    public array $records = [];

    private function record(string $level, mixed $message, mixed $extra): self
    {
        $this->records[$level][] = ['message' => (string) $message, 'extra' => $extra];
        return $this;
    }

    public function emerg($message, $extra = []): self
    {
        return $this->record('emerg', $message, $extra);
    }

    public function alert($message, $extra = []): self
    {
        return $this->record('alert', $message, $extra);
    }

    public function crit($message, $extra = []): self
    {
        return $this->record('crit', $message, $extra);
    }

    public function err($message, $extra = []): self
    {
        return $this->record('err', $message, $extra);
    }

    public function warn($message, $extra = []): self
    {
        return $this->record('warn', $message, $extra);
    }

    public function notice($message, $extra = []): self
    {
        return $this->record('notice', $message, $extra);
    }

    public function info($message, $extra = []): self
    {
        return $this->record('info', $message, $extra);
    }

    public function debug($message, $extra = []): self
    {
        return $this->record('debug', $message, $extra);
    }
}
