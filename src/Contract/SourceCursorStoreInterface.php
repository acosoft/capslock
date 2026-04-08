<?php
declare(strict_types=1);

namespace App\Contract;

interface SourceCursorStoreInterface
{
    public function getLastStoredSourceEventId(string $sourceName): ?int;

    public function setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void;
}
