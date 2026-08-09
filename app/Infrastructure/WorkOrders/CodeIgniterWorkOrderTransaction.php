<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\WorkOrders\Port\WorkOrderTransaction;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final readonly class CodeIgniterWorkOrderTransaction implements WorkOrderTransaction
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->database->transBegin();

        try {
            $result = $operation();
            if (! $this->database->transStatus()) {
                throw new RuntimeException('La transacciÃ³n de la OT no pudo completarse.');
            }
            $this->database->transCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
