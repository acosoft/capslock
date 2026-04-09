<?php
declare(strict_types=1);

namespace App\Adapter;

use App\Contract\SourceLeaseManagerInterface;
use Predis\Client as PredisClient;

final class RedisSourceLeaseManager implements SourceLeaseManagerInterface
{
    private PredisClient $client;
    private string $prefix;

    public function __construct(PredisClient $client, string $prefix = 'lease:')
    {
        $this->client = $client;
        $this->prefix = $prefix;
    }

    private function key(string $sourceName): string
    {
        return $this->prefix . $sourceName;
    }

    public function tryAcquireLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        $key = $this->key($sourceName);
        // Use EVAL to perform SET with PX and NX to avoid client option compatibility issues
        $script = "local r = redis.call('set', KEYS[1], ARGV[1], 'PX', ARGV[2], 'NX') if r then return r else return nil end";
        $res = $this->client->eval($script, 1, $key, $ownerId, (string)$ttlMs);
        return $res !== null;
    }

    public function renewLease(string $sourceName, string $ownerId, int $ttlMs): bool
    {
        $key = $this->key($sourceName);
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('pexpire', KEYS[1], ARGV[2])
end
return 0
LUA;
        $res = $this->client->eval($script, 1, $key, $ownerId, $ttlMs);
        return (int)$res > 0;
    }

    public function releaseLease(string $sourceName, string $ownerId): void
    {
        $key = $this->key($sourceName);
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('del', KEYS[1])
end
return 0
LUA;
        try {
            $this->client->eval($script, 1, $key, $ownerId);
        } catch (\Throwable $e) {
            // ignore release errors in adapter
        }
    }

    public function getLeaseOwner(string $sourceName): ?string
    {
        $key = $this->key($sourceName);
        $val = $this->client->get($key);
        return $val === null ? null : (string)$val;
    }
}
