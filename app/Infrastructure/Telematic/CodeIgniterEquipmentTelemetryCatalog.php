<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Lee los vínculos equipo ↔ integración y los agrupa por equipo.
 *
 * Una sola consulta con join: pedir las fuentes equipo por equipo sería N
 * consultas para la misma tabla. La agregación en memoria es sólo de
 * transporte; la regla que decide si el equipo está o no monitoreado vive en
 * el dominio.
 */
final class CodeIgniterEquipmentTelemetryCatalog implements \App\Application\Telematic\Port\EquipmentTelemetryCatalog
{
    private readonly BaseConnection $db;

    public function __construct(
        private readonly \App\Application\Telematic\Port\TelemetryIntegrationCatalog $integrations,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<CoberturaEquipo> */
    public function coveredEquipmentFor(int $companyId): array
    {
        if ($companyId <= 0 || ! $this->db->tableExists('equipo_telemetria')) {
            return [];
        }

        $rows = $this->db->table('equipo_telemetria et')
            ->select('et.empresa_id, et.equipo_id, et.integracion_id, et.unidad_externa, et.rol, e.sucursal_id, e.codigo, i.proveedor, i.nombre integracion_nombre')
            ->join('equipos e', 'e.id = et.equipo_id AND e.empresa_id = et.empresa_id', 'inner')
            ->join('integraciones_telemetria i', 'i.id = et.integracion_id AND i.empresa_id = et.empresa_id', 'inner')
            ->where('et.empresa_id', $companyId)
            ->where('et.activo', 1)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('i.activo', 1)
            ->orderBy('et.equipo_id', 'ASC')
            ->get()
            ->getResultArray();

        /** @var array<int, array{row:array<string,mixed>, fuentes:list<\App\Domain\Telematic\FuenteSenal>}> $agrupado */
        $agrupado = [];

        foreach ($rows as $row) {
            $equipmentId = (int) $row['equipo_id'];
            if (! isset($agrupado[$equipmentId])) {
                $agrupado[$equipmentId] = ['row' => $row, 'fuentes' => []];
            }

            $agrupado[$equipmentId]['fuentes'][] = new \App\Domain\Telematic\FuenteSenal(
                (string) $row['integracion_id'],
                (string) $row['proveedor'],
                (string) ($row['integracion_nombre'] ?? ''),
                (string) $row['unidad_externa'],
                (string) ($row['rol'] ?? 'SECUNDARIA'),
                null,
            );
        }

        $coverages = [];
        foreach ($agrupado as $entry) {
            $row = $entry['row'];
            $coverages[] = new CoberturaEquipo(
                (int) $row['empresa_id'],
                $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'],
                (int) $row['equipo_id'],
                (string) $row['codigo'],
                $entry['fuentes'],
            );
        }

        return $coverages;
    }
}