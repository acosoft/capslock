<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceClientInterface;
use App\Model\Event;

final class NullEventSourceClient implements EventSourceClientInterface
{
    public function fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): array
    {
        // no events by default; useful for smoke runs
        return [];
    }
}
