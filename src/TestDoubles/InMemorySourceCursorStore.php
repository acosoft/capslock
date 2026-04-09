<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\SourceCursorStoreInterface;

final class InMemorySourceCursorStore implements SourceCursorStoreInterface
{
    private array $cursors = [];
    private ?\Predis\Client $predis = null;
    private string $prefix = 'cursor:';

    public function __construct()
    {
        if (class_exists('\Predis\Client')) {
            $dsn = getenv('REDIS_DSN') ?: 'tcp://redis:6379';
            try {
                $this->predis = new \Predis\Client($dsn);
            } catch (\Throwable $e) {
                $this->predis = null;
            }
        }
    }

    public function getLastStoredSourceEventId(string $sourceName): ?int
    {
        // prefer Redis-backed cursor when available so tests across containers share state
        if ($this->predis !== null) {
            try {
                $val = $this->predis->get($this->prefix . $sourceName);
                if ($val === null) {
                    return null;
                }
                return (int)$val;
            } catch (\Throwable $e) {
                // fallback to in-memory
            }
        }

        return $this->cursors[$sourceName] ?? null;
    }

    public function setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void
    {
        // update Redis first if available
        if ($this->predis !== null) {
            try {
                $this->predis->set($this->prefix . $sourceName, (string)$sourceEventId);
                // keep a local copy as well for process-local reads
                $this->cursors[$sourceName] = $sourceEventId;
                return;
            } catch (\Throwable $e) {
                // fall through to in-memory fallback
            }
        }

        $this->cursors[$sourceName] = $sourceEventId;
    }
}
