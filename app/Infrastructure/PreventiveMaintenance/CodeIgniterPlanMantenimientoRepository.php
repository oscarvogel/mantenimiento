<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterPlanMantenimientoRepository implements PlanMantenimientoRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento
    {
        $builder = $this->baseScopedQuery($companyId, $branchIds)->where('pm.id', $planId);
        $row     = $this->firstRow($builder, $forUpdate);

        return $row === null ? null : $this->hydrate($row);
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return $this->baseScopedQuery($companyId, $branchIds)
            ->where('pm.equipo_id', $equipmentId)
            ->where('pm.tipo_servicio_id', $serviceTypeId)
            ->where('pm.activo', 1)
            ->countAllResults() > 0;
    }

    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        $rows = $this->baseScopedQuery($companyId, $branchIds)
            ->where('pm.activo', 1)
            ->orderBy('pm.id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn (array $row): PlanMantenimiento => $this->hydrate($row), $rows);
    }

    public function save(PlanMantenimiento $plan, int $actorUserId): int
    {
        if ($actorUserId <= 0) {
            throw new RuntimeException('El actor que guarda el plan debe ser valido.');
        }

        $data = [
            'empresa_id'         => $plan->empresaId(),
            'equipo_id'          => $plan->equipoId(),
            'tipo_servicio_id'   => $plan->tipoServicioId(),
            'intervalo_km'       => $plan->intervaloKm(),
            'intervalo_horas'    => DecimalHours::fromTenths($plan->intervaloHorasDecimas()),
            'intervalo_dias'     => $plan->intervaloDias(),
            'anticipacion_km'    => $plan->anticipacionKm(),
            'anticipacion_horas' => DecimalHours::fromTenths($plan->anticipacionHorasDecimas()),
            'anticipacion_dias'  => $plan->anticipacionDias(),
            'base_km'            => $plan->baseKm(),
            'base_horas'         => DecimalHours::fromTenths($plan->baseHorasDecimas()),
            'base_fecha'         => $plan->baseFecha()?->format('Y-m-d'),
            'proximo_km'         => $plan->proximoKm(),
            'proximas_horas'     => DecimalHours::fromTenths($plan->proximasHorasDecimas()),
            'proxima_fecha'      => $plan->proximaFecha()?->format('Y-m-d'),
            'prioridad'          => $plan->prioridad(),
            'activo'             => $plan->activo() ? 1 : 0,
            'clave_activa'       => $plan->activo() ? 1 : null,
            'observaciones'      => $plan->observaciones(),
            'updated_by'         => $actorUserId,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        if ($plan->id() === null) {
            $data['created_by'] = $actorUserId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('planes_mantenimiento')->insert($data);

            return (int) $this->db->insertID();
        }

        $updated = $this->db->table('planes_mantenimiento')
            ->where('id', $plan->id())
            ->where('empresa_id', $plan->empresaId())
            ->where('deleted_at', null)
            ->update($data);

        if (! $updated) {
            throw new RuntimeException('No se pudo persistir el plan dentro de su empresa.');
        }

        return $plan->id();
    }

    /** @param list<int>|null $branchIds */
    private function baseScopedQuery(int $companyId, ?array $branchIds): BaseBuilder
    {
        $builder = $this->db->table('planes_mantenimiento pm')
            ->select('pm.*')
            ->join('equipos e', 'e.id = pm.equipo_id AND e.empresa_id = pm.empresa_id', 'inner')
            ->where('pm.empresa_id', $companyId)
            ->where('pm.deleted_at', null)
            ->where('e.deleted_at', null);

        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
        } elseif ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        return $builder;
    }

    /** @return array<string, mixed>|null */
    private function firstRow(BaseBuilder $builder, bool $forUpdate): ?array
    {
        if (! $forUpdate) {
            return $builder->get()->getRowArray();
        }

        $sql = $builder->getCompiledSelect() . ' FOR UPDATE';

        return $this->db->query($sql)->getRowArray();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['equipo_id'],
            (int) $row['tipo_servicio_id'],
            $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            DecimalHours::toTenths($row['intervalo_horas']),
            $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
            DecimalHours::toTenths($row['anticipacion_horas']),
            $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
            $row['base_km'] === null ? null : (int) $row['base_km'],
            DecimalHours::toTenths($row['base_horas']),
            $row['base_fecha'] === null ? null : new DateTimeImmutable((string) $row['base_fecha']),
            $row['proximo_km'] === null ? null : (int) $row['proximo_km'],
            DecimalHours::toTenths($row['proximas_horas']),
            $row['proxima_fecha'] === null ? null : new DateTimeImmutable((string) $row['proxima_fecha']),
            (string) $row['prioridad'],
            (bool) $row['activo'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
        );
    }
}
