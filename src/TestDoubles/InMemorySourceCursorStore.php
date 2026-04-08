<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\SourceCursorStoreInterface;

final class InMemorySourceCursorStore implements SourceCursorStoreInterface
{
    private array $cursors = [];

    public function getLastStoredSourceEventId(string $sourceName): ?int
    {
        return $this->cursors[$sourceName] ?? null;
    }

    public function setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void
    {
        $this->cursors[$sourceName] = $sourceEventId;
    }
}
