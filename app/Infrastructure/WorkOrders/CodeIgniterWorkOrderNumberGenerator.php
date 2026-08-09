<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\WorkOrders\Port\WorkOrderNumberGenerator;
use App\Domain\WorkOrders\WorkOrderNumber;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final readonly class CodeIgniterWorkOrderNumberGenerator implements WorkOrderNumberGenerator
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function next(int $companyId, int $year): WorkOrderNumber
    {
        if ($companyId <= 0) {
            throw new RuntimeException('La empresa es obligatoria para numerar una OT.');
        }

        $now = date('Y-m-d H:i:s');
        $this->database->query(
            'INSERT IGNORE INTO orden_numeradores (empresa_id, anio, ultimo_numero, updated_at) VALUES (?, ?, 0, ?)',
            [$companyId, $year, $now],
        );
        $row = $this->database->query(
            'SELECT ultimo_numero FROM orden_numeradores WHERE empresa_id = ? AND anio = ? FOR UPDATE',
            [$companyId, $year],
        )->getRowArray();
        if ($row === null) {
            throw new RuntimeException('No se pudo bloquear el numerador de OT.');
        }

        $next = (int) $row['ultimo_numero'] + 1;
        $this->database->table('orden_numeradores')
            ->where('empresa_id', $companyId)
            ->where('anio', $year)
            ->update(['ultimo_numero' => $next, 'updated_at' => $now]);

        return WorkOrderNumber::fromSequence($year, $next);
    }
}
