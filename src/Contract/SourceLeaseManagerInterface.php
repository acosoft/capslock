<?php
declare(strict_types=1);

namespace App\Contract;

interface SourceLeaseManagerInterface
{
    public function tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool;

    public function renewLease(string $sourceName, string $ownerId, int $ttlMs): bool;

    public function releaseLease(string $sourceName, string $ownerId): void;

    public function getLeaseOwner(string $sourceName): ?string;

    /**
     * Read per-source runtime metadata stored in the coordination store (e.g. Redis key `source:{name}`).
     * Returns null if no metadata exists.
     *
     * @return array<string,mixed>|null
     */
    public function getSourceMetadata(string $sourceName): ?array;

    /**
     * Update only the `nextCallAfter` field for the given source in the coordination store.
     */
    public function setNextCallAfter(string $sourceName, int $nextCallAfterUnixMs): void;

    /**
     * Update the advisory last stored source event id in the coordination store.
     */
    public function setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void;
}
