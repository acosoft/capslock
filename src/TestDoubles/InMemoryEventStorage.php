<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventStorageInterface;
use App\Model\Event;

final class InMemoryEventStorage implements EventStorageInterface
{
    private array $store = [];

    public function storeEvents(string $sourceName, array $events): void
    {
        $this->store[$sourceName] = array_merge($this->store[$sourceName] ?? [], $events);
    }

    public function fetchLastSourceEventId(string $sourceName): ?int
    {
        $items = $this->store[$sourceName] ?? [];
        if (empty($items)) {
            return null;
        }
        $last = end($items);
        return $last instanceof Event ? $last->sourceEventId : null;
    }
}
