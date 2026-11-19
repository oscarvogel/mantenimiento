<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\PendingFuelPriceRepository;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripMoney;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterPendingFuelPriceRepository implements PendingFuelPriceRepository
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function pending(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->db->table('viaje_operativo_combustible f')
            ->select('f.id, f.viaje_id, f.fecha, f.litros, f.kilometraje, f.estacion, f.comprobante, f.observaciones, v.numero, v.estado, s.nombre AS sucursal')
            ->join('viajes_operativos v', 'v.id = f.viaje_id AND v.empresa_id = f.empresa_id')
            ->join('sucursales s', 's.id = v.sucursal_id', 'left')
            ->where('f.empresa_id', $companyId)
            ->where('f.deleted_at', null)
            ->where('f.importe', null)
            ->where('v.estado !=', Trip::CANCELADO)
            ->orderBy('f.fecha', 'ASC')
            ->orderBy('f.id', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        $rows = $builder->get()->getResultArray();
        if ($rows === []) {
            return [];
        }

        $tractors = $this->tractors($companyId, array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['viaje_id'],
            $rows,
        ))));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'viaje_id' => (int) $row['viaje_id'],
            'viaje_numero' => (int) $row['numero'],
            'viaje_estado' => (string) $row['estado'],
            'sucursal' => $row['sucursal'] === null ? null : (string) $row['sucursal'],
            'fecha' => (string) $row['fecha'],
            'litros' => (float) $row['litros'],
            'kilometraje' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
            'estacion' => $row['estacion'],
            'comprobante' => $row['comprobante'],
            'observaciones' => $row['observaciones'],
            'tractor' => $tractors[(int) $row['viaje_id']] ?? null,
        ], $rows);
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $fuelId): ?array
    {
        if ($branchIds === []) {
            return null;
        }
        $fuel = $this->db->prefixTable('viaje_operativo_combustible');
        $trips = $this->db->prefixTable('viajes_operativos');
        $sql = "SELECT f.id, f.viaje_id, f.importe, v.numero, v.estado, v.sucursal_id FROM {$fuel} f"
            . " JOIN {$trips} v ON v.id = f.viaje_id AND v.empresa_id = f.empresa_id"
            . ' WHERE f.empresa_id = ? AND f.id = ? AND f.deleted_at IS NULL';
        $bindings = [$companyId, $fuelId];
        if ($branchIds !== null) {
            $sql .= ' AND v.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            array_push($bindings, ...$branchIds);
        }
        if ($this->db->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->db->query($sql, $bindings)->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'viaje_id' => (int) $row['viaje_id'],
            'viaje_numero' => (int) $row['numero'],
            'viaje_estado' => (string) $row['estado'],
            'con_precio' => $row['importe'] !== null,
        ];
    }

    public function completePrice(int $companyId, int $fuelId, TripMoney $money, int $actorUserId): bool
    {
        $before = $this->db->table('viaje_operativo_combustible')
            ->where('empresa_id', $companyId)
            ->where('id', $fuelId)
            ->where('deleted_at', null)
            ->where('importe', null)
            ->get()->getRowArray();
        if ($before === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $changes = [
            'importe' => $this->decimal($money->amount(), 4),
            'moneda' => $money->currency(),
            'cotizacion' => $this->decimal($money->exchangeRate(), 6),
            'importe_ars' => $this->decimal($money->amountArs(), 4),
            'updated_at' => $now,
            'updated_by' => $actorUserId,
        ];
        $this->db->table('viaje_operativo_combustible')
            ->where('empresa_id', $companyId)
            ->where('id', $fuelId)
            ->where('deleted_at', null)
            ->where('importe', null)
            ->update($changes);
        if ($this->db->affectedRows() !== 1) {
            return false;
        }

        $inserted = $this->db->table('viaje_operativo_movimientos_auditoria')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => (int) $before['sucursal_id'],
            'viaje_id' => (int) $before['viaje_id'],
            'movimiento_tipo' => 'COMBUSTIBLE',
            'movimiento_id' => $fuelId,
            'accion' => 'PRECIO',
            'datos_anteriores' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'datos_nuevos' => json_encode(array_merge($before, $changes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'usuario_id' => $actorUserId,
            'created_at' => $now,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo registrar la auditoría del precio de combustible.');
        }

        return true;
    }

    public function currencies(int $companyId): array
    {
        $rates = [];
        foreach ($this->db->table('viajes_cotizaciones')->select('moneda, cotizacion')->where('empresa_id', $companyId)->get()->getResultArray() as $row) {
            $rates[strtoupper((string) $row['moneda'])] = (float) $row['cotizacion'];
        }
        $rows = $this->db->table('viajes_monedas')
            ->select('codigo, nombre')
            ->where('activo', 1)
            ->orderBy('codigo', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $row) use ($rates): array {
            $code = strtoupper((string) $row['codigo']);

            return ['code' => $code, 'label' => (string) $row['nombre'], 'rate' => $code === 'ARS' ? 1.0 : ($rates[$code] ?? null)];
        }, $rows);
    }

    /**
     * @param list<int> $tripIds
     * @return array<int,string>
     */
    private function tractors(int $companyId, array $tripIds): array
    {
        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.viaje_id, e.patente, e.codigo')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', 'TRACTOR')
            ->whereIn('a.viaje_id', $tripIds)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        $labels = [];
        foreach ($rows as $row) {
            $tripId = (int) $row['viaje_id'];
            if (isset($labels[$tripId])) {
                continue;
            }
            $plate = trim((string) ($row['patente'] ?? ''));
            $code = trim((string) ($row['codigo'] ?? ''));
            $labels[$tripId] = $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
        }

        return $labels;
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
