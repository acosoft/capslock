<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceClientInterface;
use App\Model\Event;

final class RandomEventSourceClient implements EventSourceClientInterface
{
    public function fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): array
    {
        $count = random_int(5, 20);
        $events = [];
        $start = $afterSourceEventId + 1;
        for ($i = 0; $i < $count; $i++) {
            $events[] = new Event($sourceName, $start + $i, ['generated' => true]);
        }
        return $events;
    }
}
