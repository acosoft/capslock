<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceRegistryInterface;

final class InMemoryEventSourceRegistry implements EventSourceRegistryInterface
{
    private array $sources;

    public function __construct()
    {
        // Provide five demo sources for testing with metadata shape compatible with `source:{name}`.
        $this->sources = [
            'Source1' => ['description' => 'Demo source 1', 'nextCallAfter' => 0, 'lastStoredSourceEventId' => null],
            'Source2' => ['description' => 'Demo source 2', 'nextCallAfter' => 0, 'lastStoredSourceEventId' => null],
            'Source3' => ['description' => 'Demo source 3', 'nextCallAfter' => 0, 'lastStoredSourceEventId' => null],
            'Source4' => ['description' => 'Demo source 4', 'nextCallAfter' => 0, 'lastStoredSourceEventId' => null],
            'Source5' => ['description' => 'Demo source 5', 'nextCallAfter' => 0, 'lastStoredSourceEventId' => null],
        ];
    }

    public function getSources(): array
    {
        return $this->sources;
    }
}
