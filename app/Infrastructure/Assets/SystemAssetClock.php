<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\AssetClock;
use DateTimeImmutable;

final class SystemAssetClock implements AssetClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
