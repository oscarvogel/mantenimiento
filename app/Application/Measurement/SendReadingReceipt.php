<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Measurement\Port\ReadingReceiptNotifier;
use Throwable;

final readonly class SendReadingReceipt
{
    public function __construct(private ReadingReceiptNotifier $notifier)
    {
    }

    public function execute(int $companyId, int $equipmentId, int $readingId): void
    {
        if ($companyId <= 0 || $equipmentId <= 0 || $readingId <= 0) {
            return;
        }

        try {
            $this->notifier->notify($companyId, $equipmentId, $readingId);
        } catch (Throwable $exception) {
            log_message('error', 'No se pudo preparar el acuse de lectura {reading}: {message}', [
                'reading' => $readingId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
