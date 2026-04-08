<?php
declare(strict_types=1);

namespace App\Model;

final class Event
{
    public string $sourceName;
    public int $sourceEventId;
    public array $payload;

    public function __construct(string $sourceName, int $sourceEventId, array $payload = [])
    {
        $this->sourceName = $sourceName;
        $this->sourceEventId = $sourceEventId;
        $this->payload = $payload;
    }
}
