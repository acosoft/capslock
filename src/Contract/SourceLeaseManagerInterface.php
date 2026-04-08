<?php
declare(strict_types=1);

namespace App\Contract;

interface SourceLeaseManagerInterface
{
    public function tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool;

    public function renewLease(string $sourceName, string $ownerId, int $ttlMs): bool;

    public function releaseLease(string $sourceName, string $ownerId): void;

    public function getLeaseOwner(string $sourceName): ?string;
}
