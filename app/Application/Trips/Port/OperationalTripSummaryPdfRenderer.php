<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface OperationalTripSummaryPdfRenderer
{
    /** @param array<string,mixed> $trip */
    public function render(array $trip): string;
}
