<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface DriverSettlementPdfRenderer
{
    /**
     * @param array<string,mixed> $statement Proyección segura de
     *        {@see \App\Application\Trips\DriverSettlementStatement}: el PDF
     *        no recibe el detalle administrativo del viaje.
     */
    public function render(array $statement): string;
}
