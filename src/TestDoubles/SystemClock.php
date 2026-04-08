<?php
declare(strict_types=1);

namespace App\TestDoubles;

use App\Contract\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
