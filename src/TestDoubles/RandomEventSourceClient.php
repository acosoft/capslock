<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceClientInterface;
use App\Model\Event;

final class RandomEventSourceClient implements EventSourceClientInterface
{
    public function fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): array
    {
        // simulate network / processing latency: random 3..10 seconds
            $sleepSec = random_int(3, 10);
            usleep($sleepSec * 1_000_000);

            $count = random_int(5, 20);
            $events = [];
            $this->predis = null;
            $this->prefix = 'source:seq:';

            if (class_exists('\Predis\Client')) {
                $dsn = getenv('REDIS_DSN') ?: 'tcp://redis:6379';
                $this->predis = new \Predis\Client($dsn);
            }

            // If Redis is available, allocate a block of IDs atomically per source
            if ($this->predis !== null) {
                $key = $this->prefix . $sourceName;
                // Lua script: ensure current >= afterSourceEventId, then INCRBY by count
                $script = <<<'LUA'
    local cur = redis.call('get', KEYS[1])
    local after = tonumber(ARGV[1])
    if not cur or tonumber(cur) < after then
      redis.call('set', KEYS[1], after)
      cur = tostring(after)
    end
    local new = redis.call('incrby', KEYS[1], ARGV[2])
    return {tonumber(cur), tonumber(new)}
    LUA;
                $res = $this->predis->eval($script, 1, $key, (string)$afterSourceEventId, (string)$count);
                // $res => [old, new]
                if (is_array($res) && count($res) === 2) {
                    $old = (int)$res[0];
                    $new = (int)$res[1];
                    $first = $old + 1;
                    for ($id = $first; $id <= $new; $id++) {
                        $events[] = new Event($sourceName, $id, ['generated' => true]);
                    }
                    return $events;
                }
                // fallback to in-process generation
            }

            $start = $afterSourceEventId + 1;
            for ($i = 0; $i < $count; $i++) {
                $events[] = new Event($sourceName, $start + $i, ['generated' => true]);
            }
            return $events;
    }
}
