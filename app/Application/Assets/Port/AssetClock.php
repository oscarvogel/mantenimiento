<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use DateTimeImmutable;

interface AssetClock
{
    public function today(): DateTimeImmutable;
}
