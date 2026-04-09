<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventStorageInterface;
use App\Model\Event;

final class InMemoryEventStorage implements EventStorageInterface
{
    private array $store = [];
    private ?\Predis\Client $predis = null;
    private string $eventsPrefix = 'storage:events:';
    private string $lastIdPrefix = 'storage:last:';

    public function __construct()
    {
        if (class_exists('\Predis\Client')) {
            $dsn = getenv('REDIS_DSN') ?: 'tcp://redis:6379';
            try {
                $this->predis = new \Predis\Client($dsn);
            } catch (\Throwable $e) {
                $this->predis = null;
            }
        }
    }

    public function storeEvents(string $sourceName, array $events): void
    {
        if ($this->predis !== null) {
            try {
                foreach ($events as $event) {
                    if (!$event instanceof Event) {
                        continue;
                    }

                    $this->predis->rpush($this->eventsPrefix . $sourceName, [json_encode([
                        'sourceName' => $event->sourceName,
                        'sourceEventId' => $event->sourceEventId,
                        'payload' => $event->payload,
                    ], JSON_THROW_ON_ERROR)]);
                }

                $last = end($events);
                if ($last instanceof Event) {
                    $this->predis->set($this->lastIdPrefix . $sourceName, (string)$last->sourceEventId);
                }

                $this->store[$sourceName] = array_merge($this->store[$sourceName] ?? [], $events);
                return;
            } catch (\Throwable $e) {
                // fall back to local memory when Redis is unavailable
            }
        }

        $this->store[$sourceName] = array_merge($this->store[$sourceName] ?? [], $events);
    }

    public function fetchLastSourceEventId(string $sourceName): ?int
    {
        if ($this->predis !== null) {
            try {
                $last = $this->predis->get($this->lastIdPrefix . $sourceName);
                if ($last !== null) {
                    return (int)$last;
                }
            } catch (\Throwable $e) {
                // fall through to local storage
            }
        }

        $items = $this->store[$sourceName] ?? [];
        if (empty($items)) {
            return null;
        }
        $last = end($items);
        return $last instanceof Event ? $last->sourceEventId : null;
    }
}
