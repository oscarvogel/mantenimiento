<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

final class CodeIgniterNotificationUnitOfWork implements NotificationUnitOfWork
{
    public function __construct(private ?BaseConnection $db = null) { $this->db ??= Database::connect(); }
    public function transactional(callable $operation): mixed
    {
        $this->db->transBegin();
        try {
            $result = $operation();
            if (! $this->db->transStatus()) { throw new RuntimeException('La transacción de notificaciones quedó inválida.'); }
            $this->db->transCommit();
            return $result;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
