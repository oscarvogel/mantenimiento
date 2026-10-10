<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetrySnapshotReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Última instantánea de telemetría por fuente, para la ficha del equipo.
 *
 * `ausente` significa que el proveedor no respondió en la última corrida: el
 * dato sigue ahí y se muestra, pero marcado. No se borra nunca, porque el
 * último lugar conocido sigue siendo información y una pantalla vacía es peor
 * que una desactualizada.
 */
final class CodeIgniterTelemetrySnapshotReader implements TelemetrySnapshotReader
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string,mixed>> */
    public function forEquipment(?int $companyId, int $equipmentId): array
    {
        if ($companyId === null || $companyId <= 0 || $equipmentId <= 0 || ! $this->db->tableExists('telematia_ultima_lectura')) {
            return [];
        }

        $rows = $this->db->table('telematia_ultima_lectura t')
            ->select('t.*, i.proveedor proveedor_real, i.nombre integracion_nombre')
            ->join('integraciones_telemetria i', 'i.id = t.integracion_id AND i.empresa_id = t.empresa_id', 'inner')
            ->where('t.empresa_id', $companyId)
            ->where('t.equipo_id', $equipmentId)
            ->orderBy('t.rol', 'ASC')
            ->orderBy('t.id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn (array $row): array => $this->shape($row), $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function shape(array $row): array
    {
        $adicionales = [];
        if (! empty($row['sensores_ad'])) {
            $decodificado = json_decode((string) $row['sensores_ad'], true);
            if (is_array($decodificado)) {
                $adicionales = array_values(array_filter($decodificado, 'is_array'));
            }
        }

        return [
            'integrationId' => (int) $row['integracion_id'],
            'integrationName' => (string) ($row['integracion_nombre'] ?? ''),
            'provider' => (string) $row['proveedor'],
            'externalUnitId' => (string) $row['unidad_externa'],
            'role' => (string) ($row['rol'] ?? 'SECUNDARIA'),
            'stale' => (int) ($row['ausente'] ?? 0) === 1,
            'observedAt' => $row['observada_en'],
            'recordedAt' => $row['registrada_en'],
            'position' => $row['latitud'] === null ? null : [
                'latitude' => (float) $row['latitud'],
                'longitude' => (float) $row['longitud'],
                'speedKmh' => $row['velocidad_kmh'] === null ? null : (float) $row['velocidad_kmh'],
                'course' => $row['rumbo'] === null ? null : (int) $row['rumbo'],
                'altitudeM' => $row['altitud_m'] === null ? null : (float) $row['altitud_m'],
                'satellites' => $row['satelites'] === null ? null : (int) $row['satelites'],
            ],
            'kilometers' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
            'hoursTenths' => $row['horas_decimales'] === null ? null : (int) $row['horas_decimales'],
            'engineOn' => $this->triestado($row['motor_encendido']),
            'idling' => $this->triestado($row['ralenti_activo']),
            'voltage' => $row['voltaje'] === null ? null : (float) $row['voltaje'],
            'fuelLiters' => $row['combustible_litros'] === null ? null : (float) $row['combustible_litros'],
            'extraSensors' => $adicionales,
        ];
    }

    /**
     * Tres estados, no dos. Un sensor apagado y un sensor que no reporta se
     * parecen en un switch normal, y son dos cosas que el operador atiende de
     * forma distinta.
     */
    private function triestado(mixed $raw): ?bool
    {
        return $raw === null ? null : (int) $raw === 1;
    }
}