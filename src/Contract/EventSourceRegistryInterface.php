<?php
declare(strict_types=1);

namespace App\Contract;

interface EventSourceRegistryInterface
{
    /**
     * Return a map where keys are source names and values are JSON-serializable metadata.
     * @return array<string, mixed>
     */
    public function getSources(): array;
}
