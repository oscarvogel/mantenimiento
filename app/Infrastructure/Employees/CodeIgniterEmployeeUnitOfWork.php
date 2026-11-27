<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\Port\EmployeeUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class CodeIgniterEmployeeUnitOfWork implements EmployeeUnitOfWork
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->database->transBegin();
        try {
            $result = $operation();
            if (! $this->database->transStatus()) {
                throw new RuntimeException('La transacción de empleados y asignaciones no pudo completarse.');
            }
            $this->database->transCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
