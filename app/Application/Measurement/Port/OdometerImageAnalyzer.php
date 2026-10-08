<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Application\Measurement\OdometerImageAnalysis;

interface OdometerImageAnalyzer
{
    public function analyze(string $absolutePath, string $mimeType): OdometerImageAnalysis;
}
