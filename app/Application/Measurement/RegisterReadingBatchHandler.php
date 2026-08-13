<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\RegisterReadingAndReevaluate;
use Throwable;

final readonly class RegisterReadingBatchHandler
{
    public function __construct(
        private RegisterReadingAndReevaluate $registerAndReevaluate,
    ) {
    }

    /** @param list<RegisterReadingBatchItem> $items */
    public function execute(ActorContext $actor, array $items): RegisterReadingBatchResult
    {
        $rows = [];
        foreach ($items as $item) {
            try {
                $result = $this->registerAndReevaluate->execute($actor, new RegisterReadingCommand(
                    $item->equipmentId,
                    $item->recordedAt,
                    $item->kilometers,
                    $item->hours,
                    'CARGA_RAPIDA',
                    null,
                    null,
                    $item->notes,
                ));
                $reading = $result['reading'];
                $preventive = $result['preventive'];
            } catch (Throwable $exception) {
                $rows[] = [
                    'rowNumber' => $item->rowNumber,
                    'equipmentId' => $item->equipmentId,
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'readingId' => null,
                    'currentKilometers' => null,
                    'currentHours' => null,
                    'plansEvaluated' => 0,
                    'overduePlans' => 0,
                    'noticeIds' => [],
                ];
                continue;
            }
            $message = $preventive['overdue'] > 0
                ? 'Lectura registrada y mantenimiento vencido actualizado.'
                : 'Lectura registrada y planes reevaluados.';
            $rows[] = [
                'rowNumber' => $item->rowNumber,
                'equipmentId' => $item->equipmentId,
                'success' => true,
                'message' => $message,
                'readingId' => $reading->readingId,
                'currentKilometers' => $reading->currentKilometers,
                'currentHours' => $reading->currentHours,
                'plansEvaluated' => $preventive['evaluated'],
                'overduePlans' => $preventive['overdue'],
                'noticeIds' => $preventive['notices'],
            ];
        }

        return new RegisterReadingBatchResult($rows);
    }
}
