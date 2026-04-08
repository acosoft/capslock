<?php
declare(strict_types=1);

namespace App\Contract;

interface ClockInterface
{
    /**
     * Return unix-ms timestamp
     */
    public function now(): int;
}
