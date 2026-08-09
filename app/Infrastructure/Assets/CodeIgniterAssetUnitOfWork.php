<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class CodeIgniterAssetUnitOfWork implements AssetUnitOfWork
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->database->transBegin();

        try {
            $result = $operation();
            if ($this->database->transStatus() === false) {
                throw new RuntimeException('La transacción de activos no pudo completarse.');
            }
            $this->database->transCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }
}
