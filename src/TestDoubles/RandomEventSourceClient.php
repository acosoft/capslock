<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceClientInterface;
use App\Model\Event;

final class RandomEventSourceClient implements EventSourceClientInterface
{
    private ?\Predis\Client $predis = null;
    private string $prefix = 'source:seq:';

    public function fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): array
    {
        // simulate network / processing latency: random 3..10 seconds
        $sleepSec = random_int(3, 10);
        usleep($sleepSec * 1_000_000);

        $count = random_int(5, 20);
        $events = [];

        // lazy-init Predis client if available
        if ($this->predis === null && class_exists('\Predis\Client')) {
            $dsn = getenv('REDIS_DSN') ?: 'tcp://redis:6379';
            try {
                $this->predis = new \Predis\Client($dsn);
            } catch (\Throwable $e) {
                $this->predis = null;
            }
        }

        // If Redis is available, allocate a block of IDs atomically per source
        if ($this->predis !== null) {
            $key = $this->prefix . $sourceName;
            // Lua: ensure current >= afterSourceEventId, then set new = max(current, after) + count, store and return {current, new}
            $script = <<<'LUA'
local cur = tonumber(redis.call('get', KEYS[1]) or '0')
local after = tonumber(ARGV[1])
if cur < after then cur = after end
local cnt = tonumber(ARGV[2])
local new = cur + cnt
redis.call('set', KEYS[1], tostring(new))
return {cur, new}
LUA;
            try {
                $res = $this->predis->eval($script, 1, $key, (string)$afterSourceEventId, (string)$count);
                if (is_array($res) && count($res) === 2) {
                    $old = (int)$res[0];
                    $new = (int)$res[1];
                    $first = $old + 1;
                    for ($id = $first; $id <= $new; $id++) {
                        $events[] = new Event($sourceName, $id, ['generated' => true]);
                    }
                    return $events;
                }
            } catch (\Throwable $e) {
                // fallback to in-process generation on Redis errors
            }
        }

        // fallback: generate sequential IDs in-process starting after $afterSourceEventId
        $start = $afterSourceEventId + 1;
        for ($i = 0; $i < $count; $i++) {
            $events[] = new Event($sourceName, $start + $i, ['generated' => true]);
        }

        return $events;
    }
}
