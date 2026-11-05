<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

interface ReadingReceiptNotifier
{
    public function notify(int $companyId, int $equipmentId, int $readingId): void;
}
