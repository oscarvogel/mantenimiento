<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\Port\ReadingPreventiveUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final readonly class CodeIgniterReadingPreventiveUnitOfWork implements ReadingPreventiveUnitOfWork
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->database->transBegin();
        try {
            $result = $operation();
            if ($this->database->transStatus() === false) {
                throw new RuntimeException('No se pudo confirmar lectura, reevaluación y aviso en conjunto.');
            }
            $this->database->transCommit();
            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
