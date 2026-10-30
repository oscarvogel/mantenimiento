<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

final class NormalizedLocalityCsvReader
{
    public function records(string $path): NormalizedLocalityCsvRecords
    {
        return new NormalizedLocalityCsvRecords($path);
    }
}
