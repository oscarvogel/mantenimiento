<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PreventiveUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

final class CodeIgniterPreventiveUnitOfWork implements PreventiveUnitOfWork
{
    private BaseConnection $database;

    public function __construct(?BaseConnection $database = null)
    {
        $this->database = $database ?? Database::connect();
    }

    public function transactional(callable $operation): mixed
    {
        $this->database->transBegin();

        try {
            $result = $operation();
            if ($this->database->transStatus() === false || ! $this->database->transCommit()) {
                throw new RuntimeException('No se pudo confirmar la asignacion de planes preventivos.');
            }

            return $result;
        } catch (Throwable $error) {
            $this->database->transRollback();
            throw $error;
        }
    }
}
