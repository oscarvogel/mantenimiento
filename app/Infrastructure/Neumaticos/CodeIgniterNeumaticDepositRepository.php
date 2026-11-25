<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticDepositRepository;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticDepositStock;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterNeumaticDepositRepository implements NeumaticDepositRepository
{
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isActiveDeposit(int $companyId, ?array $branchIds, int $depositId): bool
    {
        if ($branchIds === []) {
            return false;
        }
        $query = $this->database->table('depositos')
            ->where('empresa_id', $companyId)
            ->where('id', $depositId)
            ->where('activo', 1);
        if ($branchIds !== null) {
            $query->whereIn('sucursal_id', $branchIds);
        }

        return $query->countAllResults() === 1;
    }

    public function stockForUpdate(int $depositId, int $neumaticId): ?NeumaticDepositStock
    {
        $sql = 'SELECT cantidad FROM ' . $this->database->prefixTable('deposito_stock') . ' WHERE deposito_id = ? AND neumatico_id = ?';
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->database->query($sql, [$depositId, $neumaticId])->getRowArray();

        return $row === null ? null : NeumaticDepositStock::reconstitute($depositId, $neumaticId, (int) $row['cantidad']);
    }

    public function record(int $companyId, NeumaticDepositStock $stock, NeumaticDepositMovement $movement, int $actorUserId): void
    {
        $now = date(self::DATETIME);
        $key = [
            'empresa_id' => $companyId,
            'deposito_id' => $stock->depositId(),
            'neumatico_id' => $stock->neumaticId(),
        ];
        $exists = $this->database->table('deposito_stock')->where($key)->countAllResults() > 0;
        if ($exists) {
            $this->database->table('deposito_stock')->where($key)->update(['cantidad' => $stock->quantity(), 'actualizado_at' => $now]);
        } else {
            $this->database->table('deposito_stock')->insert($key + ['cantidad' => $stock->quantity(), 'actualizado_at' => $now]);
        }

        $this->database->table('deposito_movimientos')->insert($key + [
            'tipo' => $movement->type,
            'fecha' => $movement->at->format(self::DATETIME),
            'numero_planilla' => $movement->sheetNumber === '' ? null : $movement->sheetNumber,
            'cantidad' => $movement->quantity,
            'usuario_id' => $actorUserId,
            'observaciones' => $movement->notes === '' ? null : $movement->notes,
            'created_at' => $now,
        ]);
    }
}
