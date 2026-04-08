<?php
declare(strict_types=1);

namespace App\Contract;

use App\Model\Event;

interface EventStorageInterface
{
    /**
     * Persist a batch of events atomically for the given source.
     * @param Event[] $events
     */
    public function storeEvents(string $sourceName, array $events): void;

    public function fetchLastSourceEventId(string $sourceName): ?int;
}
