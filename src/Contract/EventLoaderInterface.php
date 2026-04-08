<?php
declare(strict_types=1);

namespace App\Contract;

interface EventLoaderInterface
{
    public function runLoop(): void;

    public function runOnce(): void;

    public function stop(): void;
}
