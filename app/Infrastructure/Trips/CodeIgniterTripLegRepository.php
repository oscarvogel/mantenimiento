<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripLegRepository;
use App\Domain\Trips\TripLeg;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterTripLegRepository implements TripLegRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function add(int $companyId, int $branchId, int $tripId, TripLeg $leg, int $actorUserId): int
    {
        if ($companyId <= 0 || $branchId <= 0 || $tripId <= 0) {
            throw new RuntimeException('No se puede agregar un tramo sin identificar empresa, sucursal y viaje.');
        }

        $next = $this->database->query(
            'SELECT COALESCE(MAX(orden), 0) + 1 AS next_order FROM ' . $this->database->prefixTable('viaje_operativo_tramos')
            . ' WHERE empresa_id = ? AND viaje_id = ?',
            [$companyId, $tripId],
        )->getRowArray();
        $order = (int) ($next['next_order'] ?? 1);
        $now = date('Y-m-d H:i:s');

        $inserted = $this->database->table('viaje_operativo_tramos')->insert([
            'viaje_id' => $tripId,
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'orden' => $order,
            'cliente_id' => $leg->customerId(),
            'codigo_origen_destino' => $leg->originDestinationCode(),
            'localidad_origen_id' => $leg->originLocalityId(),
            'localidad_destino_id' => $leg->destinationLocalityId(),
            'origen' => $leg->origin(),
            'destino' => $leg->destination(),
            'km' => $leg->kilometers() === null ? null : $this->decimal($leg->kilometers(), 2),
            'toneladas' => $leg->tonnes() === null ? null : $this->decimal($leg->tonnes(), 2),
            'moneda' => $leg->currency(),
            'cotizacion' => $this->decimal($leg->exchangeRate(), 6),
            'flete_facturacion' => $this->decimal($leg->invoiceAmount(), 4),
            'flete_facturacion_ars' => $this->decimal($leg->invoiceAmountArs(), 4),
            'flete_liquidacion' => $this->decimal($leg->liquidationAmount(), 4),
            'flete_liquidacion_ars' => $this->decimal($leg->liquidationAmountArs(), 4),
            'observaciones' => $leg->notes(),
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo guardar el tramo del viaje.');
        }

        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo guardar el tramo del viaje.');
        }

        return $id;
    }

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool
    {
        return $this->database->table('viaje_operativo_tramos')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $legId)
            ->countAllResults() > 0;
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
