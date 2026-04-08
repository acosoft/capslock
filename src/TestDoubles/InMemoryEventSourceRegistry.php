<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\EventSourceRegistryInterface;

final class InMemoryEventSourceRegistry implements EventSourceRegistryInterface
{
    private array $sources;

    public function __construct()
    {
        // provide five demo sources for testing
        $this->sources = [
            'Source1' => ['description' => 'Demo source 1'],
            'Source2' => ['description' => 'Demo source 2'],
            'Source3' => ['description' => 'Demo source 3'],
            'Source4' => ['description' => 'Demo source 4'],
            'Source5' => ['description' => 'Demo source 5'],
        ];
    }

    public function getSources(): array
    {
        return $this->sources;
    }
}
