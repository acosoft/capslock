<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\SourceLeaseManagerInterface;

final class InMemorySourceLeaseManager implements SourceLeaseManagerInterface
{
    /**
     * owners map: sourceName => ['owner' => string, 'expiresAt' => float]
     * expiresAt is unix seconds with microseconds (float)
     */
    private array $owners = [];

    public function tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        $now = microtime(true);
        // clear expired owner
        if (isset($this->owners[$sourceName]) && ($this->owners[$sourceName]['expiresAt'] ?? 0) <= $now) {
            unset($this->owners[$sourceName]);
        }

        // if already owned by requester, renew
        if (isset($this->owners[$sourceName]) && $this->owners[$sourceName]['owner'] === $ownerId) {
            $this->owners[$sourceName]['expiresAt'] = $now + ($ttlMs / 1000);
            return true;
        }

        // if free, simulate occasional contention (random lost race)
        if (!isset($this->owners[$sourceName])) {
            // 30% chance to simulate a lost race
            if (random_int(1, 100) <= 30) {
                return false;
            }
            $this->owners[$sourceName] = [
                'owner' => $ownerId,
                'expiresAt' => $now + ($ttlMs / 1000),
            ];
            return true;
        }

        // otherwise owned by someone else
        return false;
    }

    public function renewLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        $now = microtime(true);
        if (!isset($this->owners[$sourceName])) {
            return false;
        }
        if ($this->owners[$sourceName]['owner'] !== $ownerId) {
            return false;
        }
        $this->owners[$sourceName]['expiresAt'] = $now + ($ttlMs / 1000);
        return true;
    }

    public function releaseLease(string $sourceName, string $ownerId): void
    {
        if (isset($this->owners[$sourceName]) && $this->owners[$sourceName]['owner'] === $ownerId) {
            unset($this->owners[$sourceName]);
        }
    }

    public function getLeaseOwner(string $sourceName): ?string
    {
        $now = microtime(true);
        if (!isset($this->owners[$sourceName])) {
            return null;
        }
        if (($this->owners[$sourceName]['expiresAt'] ?? 0) <= $now) {
            unset($this->owners[$sourceName]);
            return null;
        }
        return $this->owners[$sourceName]['owner'];
    }
}
