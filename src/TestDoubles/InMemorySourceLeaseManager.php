<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\SourceLeaseManagerInterface;

final class InMemorySourceLeaseManager implements SourceLeaseManagerInterface
{
    private array $owners = [];

    public function tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        if (!isset($this->owners[$sourceName]) || $this->owners[$sourceName] === $ownerId) {
            $this->owners[$sourceName] = $ownerId;
            return true;
        }
        return false;
    }

    public function renewLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        if (($this->owners[$sourceName] ?? null) === $ownerId) {
            return true;
        }
        return false;
    }

    public function releaseLease(string $sourceName, string $ownerId): void
    {
        if (($this->owners[$sourceName] ?? null) === $ownerId) {
            unset($this->owners[$sourceName]);
        }
    }

    public function getLeaseOwner(string $sourceName): ?string
    {
        return $this->owners[$sourceName] ?? null;
    }
}
