<?php
declare(strict_types=1);

namespace App\Adapter;

use App\Contract\SourceLeaseManagerInterface;
use Predis\Client as PredisClient;

final class RedisSourceLeaseManager implements SourceLeaseManagerInterface
{
    private PredisClient $client;
    private string $prefix;
    private string $metadataPrefix;

    public function __construct(PredisClient $client, string $prefix = 'lock:', string $metadataPrefix = 'source:')
    {
        $this->client = $client;
        $this->prefix = $prefix;
        $this->metadataPrefix = $metadataPrefix;
    }

    private function key(string $sourceName): string
    {
        return $this->prefix . $sourceName;
    }

    private function metadataKey(string $sourceName): string
    {
        return $this->metadataPrefix . $sourceName;
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
    $res = $this->client->eval($script, 1, $key, $ownerId, (string)$ttlMs);
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

    public function getSourceMetadata(string $sourceName): ?array
    {
        $raw = $this->client->get($this->metadataKey($sourceName));
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function setNextCallAfter(string $sourceName, int $nextCallAfterUnixMs): void
    {
        $metadata = $this->getSourceMetadata($sourceName) ?? [];
        $metadata['nextCallAfter'] = $nextCallAfterUnixMs;

        $this->client->set($this->metadataKey($sourceName), json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function setLastStoredSourceEventId(string $sourceName, int $sourceEventId): void
    {
        $metadata = $this->getSourceMetadata($sourceName) ?? [];
        $metadata['lastStoredSourceEventId'] = $sourceEventId;

        $this->client->set($this->metadataKey($sourceName), json_encode($metadata, JSON_THROW_ON_ERROR));
    }
}
