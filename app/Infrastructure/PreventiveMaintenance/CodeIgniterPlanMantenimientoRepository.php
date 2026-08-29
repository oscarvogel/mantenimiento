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

        // Compatibilidad temporal de persistencia: mientras las columnas legacy sigan
        // existiendo se mantienen sincronizadas. La fuente de verdad al leer ya es
        // `tipos_servicio`; estas columnas se eliminarán en el cutover final de #76.
        $data = [
            'empresa_id'         => $plan->empresaId(),
            'equipo_id'          => $plan->equipoId(),
            'tipo_servicio_id'   => $plan->tipoServicioId(),
            'origen_plantilla_id' => $plan->origenPlantillaId(),
            'origen_plantilla_item_id' => $plan->origenPlantillaItemId(),
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
            ->select('ts.intervalo_km service_intervalo_km, ts.intervalo_horas service_intervalo_horas, ts.intervalo_dias service_intervalo_dias')
            ->select('ts.anticipacion_km service_anticipacion_km, ts.anticipacion_horas service_anticipacion_horas, ts.anticipacion_dias service_anticipacion_dias')
            ->select('ts.prioridad service_prioridad')
            ->join('equipos e', 'e.id = pm.equipo_id AND e.empresa_id = pm.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = pm.tipo_servicio_id AND ts.empresa_id = pm.empresa_id', 'inner')
            ->where('pm.empresa_id', $companyId)
            ->where('pm.deleted_at', null)
            ->where('e.deleted_at', null)
            ->where('ts.activo', 1);

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
        $intervalKm = $row['service_intervalo_km'] === null ? null : (int) $row['service_intervalo_km'];
        $intervalHoursTenths = DecimalHours::toTenths($row['service_intervalo_horas']);
        $intervalDays = $row['service_intervalo_dias'] === null ? null : (int) $row['service_intervalo_dias'];
        $warningKm = $intervalKm === null ? null : ($row['service_anticipacion_km'] === null ? 0 : (int) $row['service_anticipacion_km']);
        $warningHoursTenths = $intervalHoursTenths === null ? null : (DecimalHours::toTenths($row['service_anticipacion_horas']) ?? 0);
        $warningDays = $intervalDays === null ? null : ($row['service_anticipacion_dias'] === null ? 0 : (int) $row['service_anticipacion_dias']);

        $baseKm = $intervalKm === null || $row['base_km'] === null ? null : (int) $row['base_km'];
        $baseHoursTenths = $intervalHoursTenths === null ? null : DecimalHours::toTenths($row['base_horas']);
        $baseDate = $intervalDays === null || $row['base_fecha'] === null ? null : new DateTimeImmutable((string) $row['base_fecha']);

        // Los próximos objetivos se derivan siempre de Servicio + base del equipo.
        // No se confía en los `proximo_*` persistidos por el modelo legacy.
        $nextKm = $baseKm === null ? null : $baseKm + $intervalKm;
        $nextHoursTenths = $baseHoursTenths === null ? null : $baseHoursTenths + $intervalHoursTenths;
        $nextDate = $baseDate === null ? null : $baseDate->modify('+' . $intervalDays . ' days');

        return PlanMantenimiento::reconstituir(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['equipo_id'],
            (int) $row['tipo_servicio_id'],
            $intervalKm,
            $intervalHoursTenths,
            $intervalDays,
            $warningKm,
            $warningHoursTenths,
            $warningDays,
            $baseKm,
            $baseHoursTenths,
            $baseDate,
            $nextKm,
            $nextHoursTenths,
            $nextDate,
            (string) $row['service_prioridad'],
            (bool) $row['activo'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            null,
            null,
        );
    }
}
