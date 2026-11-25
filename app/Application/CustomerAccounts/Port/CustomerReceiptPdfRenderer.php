<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

interface CustomerReceiptPdfRenderer
{
    /**
     * @param array<string,mixed> $receipt Proyección de CustomerReceiptReadModel::detail()
     */
    public function render(array $receipt): string;
}
