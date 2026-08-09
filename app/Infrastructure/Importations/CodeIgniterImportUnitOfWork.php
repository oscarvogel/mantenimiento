<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\ImportUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class CodeIgniterImportUnitOfWork implements ImportUnitOfWork
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
                throw new RuntimeException('La transaccion de importacion no pudo completarse.');
            }
            $this->database->transCommit();
            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
