<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\UnitOfWork;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class CodeIgniterUnitOfWork implements UnitOfWork
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
                throw new RuntimeException('La transacción de lectura no pudo completarse.');
            }
            $this->database->transCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
