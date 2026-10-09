<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\IntegracionTelematrica;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Integraciones activas desde `integraciones_telemetria`.
 *
 * Devuelve el DTO sin credenciales a propósito: el token nunca sube a la capa
 * de aplicación.
 */
final class CodeIgniterTelemetryIntegrationCatalog implements TelemetryIntegrationCatalog
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<IntegracionTelematrica> */
    public function active(): array
    {
        if (! $this->db->tableExists('integraciones_telemetria')) {
            return [];
        }

        $rows = $this->db->table('integraciones_telemetria')
            ->select('id, empresa_id, proveedor, nombre')
            ->where('activo', 1)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $integrations = [];
        foreach ($rows as $row) {
            $integrations[] = new IntegracionTelematrica(
                (int) $row['id'],
                (int) $row['empresa_id'],
                (string) $row['proveedor'],
                (string) $row['nombre'],
            );
        }

        return $integrations;
    }
}