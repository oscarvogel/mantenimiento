<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EstadoGestionAviso;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterMaintenanceNoticeRepository implements MaintenanceNoticeRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan
    {
        $row = $this->db->table('avisos_plan')
            ->where('empresa_id', $companyId)
            ->where('plan_id', $planId)
            ->where('clave_ciclo', $cycleKey)
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan
    {
        $builder = $this->db->table('avisos_plan ap')
            ->select('ap.*')
            ->join('equipos e', 'e.id = ap.equipo_id AND e.empresa_id = ap.empresa_id', 'inner')
            ->where('ap.empresa_id', $companyId)
            ->where('ap.id', $noticeId);

        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
        } elseif ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        $row = $this->firstRow($builder, $forUpdate);

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(AvisoPlan $notice, ?int $actorUserId): int
    {
        $data = [
            'empresa_id'             => $notice->empresaId(),
            'plan_id'                => $notice->planId(),
            'equipo_id'              => $notice->equipoId(),
            'clave_ciclo'            => $notice->claveCiclo(),
            'estado_calculado'       => 'VENCIDO',
            'criterios_disparadores' => implode(',', $notice->criteriosDisparadores()),
            'fecha_deteccion'        => $notice->fechaDeteccion()->format('Y-m-d H:i:s'),
            'estado_gestion'         => $notice->estadoGestion()->value,
            'fecha_resolucion'       => $notice->fechaResolucion()?->format('Y-m-d H:i:s'),
            'motivo_resolucion'      => $notice->motivoResolucion(),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($notice->id() === null) {
            $data['created_by'] = $actorUserId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('avisos_plan')->insert($data);

            return (int) $this->db->insertID();
        }

        $updated = $this->db->table('avisos_plan')
            ->where('id', $notice->id())
            ->where('empresa_id', $notice->empresaId())
            ->update($data);

        if (! $updated) {
            throw new RuntimeException('No se pudo persistir el aviso dentro de su empresa.');
        }

        return $notice->id();
    }

    /** @return array<string, mixed>|null */
    private function firstRow(BaseBuilder $builder, bool $forUpdate): ?array
    {
        if (! $forUpdate) {
            return $builder->get()->getRowArray();
        }

        return $this->db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AvisoPlan
    {
        if ((string) $row['estado_calculado'] !== 'VENCIDO') {
            throw new RuntimeException('El primer hito solo materializa avisos para planes vencidos.');
        }

        return AvisoPlan::reconstituir(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['plan_id'],
            (int) $row['equipo_id'],
            (string) $row['clave_ciclo'],
            array_values(array_filter(explode(',', (string) $row['criterios_disparadores']))),
            new DateTimeImmutable((string) $row['fecha_deteccion']),
            EstadoGestionAviso::from((string) $row['estado_gestion']),
            $row['fecha_resolucion'] === null ? null : new DateTimeImmutable((string) $row['fecha_resolucion']),
            $row['motivo_resolucion'] === null ? null : (string) $row['motivo_resolucion'],
        );
    }
}
