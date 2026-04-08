<?php
declare(strict_types=1);

namespace App\Contract;

use App\Model\Event;

interface EventSourceClientInterface
{
    /**
     * @return Event[]
     */
    public function fetch(string $sourceName, int $afterSourceEventId, int $limit = 1000): array;
}
