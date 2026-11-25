<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticDepositReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Lee depósitos, existencias y movimientos. La sucursal (Organización) y el
 * usuario (Identidad) se leen solo para mostrar su nombre. No escribe.
 */
final class CodeIgniterNeumaticDepositReadModel implements NeumaticDepositReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function deposits(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $stock = $this->database->table('deposito_stock')
            ->select('deposito_id, SUM(CASE WHEN cantidad > 0 THEN 1 ELSE 0 END) AS tires, SUM(CASE WHEN cantidad < 0 THEN 1 ELSE 0 END) AS negative')
            ->where('empresa_id', $companyId)
            ->groupBy('deposito_id')
            ->get()->getResultArray();
        $byDeposit = [];
        foreach ($stock as $row) {
            $byDeposit[(int) $row['deposito_id']] = ['tires' => (int) $row['tires'], 'negative' => (int) $row['negative']];
        }

        $query = $this->database->table('depositos d')
            ->select('d.id, d.nombre, d.ubicacion, s.nombre AS branch_name')
            ->join('sucursales s', 's.id = d.sucursal_id AND s.empresa_id = d.empresa_id', 'inner')
            ->where('d.empresa_id', $companyId)
            ->where('d.activo', 1)
            ->orderBy('d.nombre', 'ASC');
        if ($branchIds !== null) {
            $query->whereIn('d.sucursal_id', $branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'branchName' => (string) $row['branch_name'],
            'location' => (string) ($row['ubicacion'] ?? ''),
            'tires' => $byDeposit[(int) $row['id']]['tires'] ?? 0,
            'negative' => $byDeposit[(int) $row['id']]['negative'] ?? 0,
        ], $query->get()->getResultArray());
    }

    public function stock(int $companyId, ?array $branchIds, ?int $depositId): array
    {
        if ($branchIds === []) {
            return [];
        }
        $query = $this->scopedDeposits('deposito_stock x', $companyId, $branchIds, $depositId)
            ->select('x.deposito_id, d.nombre AS deposit_name, x.neumatico_id, x.cantidad, x.actualizado_at, '
                . 'n.numero, n.estado, n.medida, b.nombre AS brand_name, m.nombre AS model_name')
            ->join('neumaticos n', 'n.id = x.neumatico_id AND n.empresa_id = x.empresa_id', 'inner')
            ->join('neumatico_marcas b', 'b.id = n.marca_id AND b.empresa_id = n.empresa_id', 'left')
            ->join('neumatico_modelos m', 'm.id = n.modelo_id AND m.empresa_id = n.empresa_id', 'left')
            ->where('x.cantidad !=', 0)
            ->orderBy('d.nombre', 'ASC')
            ->orderBy('n.numero', 'ASC');

        return array_map(fn (array $row): array => [
            'depositId' => (int) $row['deposito_id'],
            'depositName' => (string) $row['deposit_name'],
            'neumaticId' => (int) $row['neumatico_id'],
            'number' => (string) $row['numero'],
            'status' => (string) $row['estado'],
            'description' => $this->description($row),
            'quantity' => (int) $row['cantidad'],
            'updatedAt' => $row['actualizado_at'] === null ? null : (string) $row['actualizado_at'],
        ], $query->get()->getResultArray());
    }

    public function movements(int $companyId, ?array $branchIds, ?int $depositId, int $limit): array
    {
        if ($branchIds === []) {
            return [];
        }
        $query = $this->scopedDeposits('deposito_movimientos x', $companyId, $branchIds, $depositId)
            ->select('x.fecha, x.tipo, d.nombre AS deposit_name, x.neumatico_id, n.numero, x.cantidad, x.numero_planilla, x.observaciones, u.nombre AS user_name')
            ->join('neumaticos n', 'n.id = x.neumatico_id AND n.empresa_id = x.empresa_id', 'inner')
            ->join('usuarios u', 'u.id = x.usuario_id', 'left')
            ->orderBy('x.fecha', 'DESC')
            ->orderBy('x.id', 'DESC')
            ->limit($limit);

        return array_map(static fn (array $row): array => [
            'at' => (string) $row['fecha'],
            'type' => (string) $row['tipo'],
            'depositName' => (string) $row['deposit_name'],
            'neumaticId' => (int) $row['neumatico_id'],
            'number' => (string) $row['numero'],
            'quantity' => (int) $row['cantidad'],
            'sheetNumber' => $row['numero_planilla'] === null ? null : (string) $row['numero_planilla'],
            'notes' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
            'userName' => $row['user_name'] === null ? null : (string) $row['user_name'],
        ], $query->get()->getResultArray());
    }

    public function tires(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $query = $this->database->table('neumaticos n')
            ->select('n.id, n.numero, n.estado, n.medida, b.nombre AS brand_name, m.nombre AS model_name')
            ->join('neumatico_marcas b', 'b.id = n.marca_id AND b.empresa_id = n.empresa_id', 'left')
            ->join('neumatico_modelos m', 'm.id = n.modelo_id AND m.empresa_id = n.empresa_id', 'left')
            ->where('n.empresa_id', $companyId)
            ->orderBy('n.numero', 'ASC');
        if ($branchIds !== null) {
            $query->whereIn('n.sucursal_id', $branchIds);
        }

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => (string) $row['numero'],
            'status' => (string) $row['estado'],
            'description' => $this->description($row),
        ], $query->get()->getResultArray());
    }

    /**
     * Une la tabla con su depósito y aplica empresa, sucursales y filtro.
     *
     * @param list<int>|null $branchIds
     */
    private function scopedDeposits(string $table, int $companyId, ?array $branchIds, ?int $depositId): BaseBuilder
    {
        $query = $this->database->table($table)
            ->join('depositos d', 'd.id = x.deposito_id AND d.empresa_id = x.empresa_id', 'inner')
            ->where('x.empresa_id', $companyId);
        if ($branchIds !== null) {
            $query->whereIn('d.sucursal_id', $branchIds);
        }
        if ($depositId !== null) {
            $query->where('x.deposito_id', $depositId);
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function description(array $row): string
    {
        return implode(' · ', array_filter([
            $row['brand_name'] ?? null,
            $row['model_name'] ?? null,
            $row['medida'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
