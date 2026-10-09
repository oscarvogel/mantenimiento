<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryRefreshGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Última lectura efectiva de una integración, según la propia base.
 *
 * Se apoya en `registrada_en`, que es cuándo guardamos nosotros, y no en la
 * señal del proveedor: el enfriamiento tiene que ver con cuánto llamamos a
 * Wialon, no con cuándo reportan los camiones.
 */
final class CodeIgniterTelemetryRefreshGuard implements TelemetryRefreshGuard
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function ultimaLecturaDe(int $integrationId): ?DateTimeImmutable
    {
        if (! $this->db->tableExists('telematia_ultima_lectura')) {
            return null;
        }

        $registrada = $this->db->table('telematia_ultima_lectura')
            ->selectMax('registrada_en', 'registrada')
            ->where('integracion_id', $integrationId)
            ->get()
            ->getRowArray();

        if ($registrada === null || empty($registrada['registrada'])) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $registrada['registrada']);
        } catch (\Throwable) {
            return null;
        }
    }
}