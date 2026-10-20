<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

use App\Application\TripImports\TripInvoiceLineRow;
use App\Application\TripImports\TripInvoiceRow;

interface TripInvoiceMapper
{
    /** @param array<string,string|null> $row */
    public function invoice(array $row): TripInvoiceRow;

    /** @param array<string,string|null> $row */
    public function line(array $row): TripInvoiceLineRow;
}
