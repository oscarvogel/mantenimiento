<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryCredentialStore;
use App\Application\Telematic\Port\TelemetryEquipmentUnitLinkManager;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use Throwable;

final class CodeIgniterTelemetryEquipmentUnitLinkManager implements TelemetryEquipmentUnitLinkManager
{
    private readonly BaseConnection $db;
    private readonly TelemetryCredentialStore $credentials;
    private readonly WialonUnitCatalogClient $wialon;

    public function __construct(
        ?BaseConnection $db = null,
        ?TelemetryCredentialStore $credentials = null,
        ?WialonUnitCatalogClient $wialon = null,
    ) {
        $this->db = $db ?? Database::connect();
        $this->credentials = $credentials ?? new CodeIgniterTelemetryIntegrationStore($this->db);
        $this->wialon = $wialon ?? new WialonUnitCatalogClient();
    }

    public function snapshotFor(int $companyId, int $integrationId): array
    {
        $integration = $this->integration($companyId, $integrationId);
        $units = $this->units($integrationId);
        $equipment = $this->equipment($companyId);

        $links = $this->db->table('equipo_telemetria')
            ->select('equipo_id, unidad_externa')
            ->where('empresa_id', $companyId)
            ->where('integracion_id', $integrationId)
            ->where('activo', 1)
            ->get()
            ->getResultArray();

        $currentLinks = [];
        foreach ($links as $link) {
            $currentLinks[(int) $link['equipo_id']] = (string) $link['unidad_externa'];
        }

        return [
            'integration' => [
                'id' => $integrationId,
                'name' => (string) $integration['nombre'],
                'provider' => (string) $integration['proveedor'],
            ],
            'equipment' => $equipment,
            'units' => $units,
            'links' => $currentLinks,
        ];
    }

    public function saveFor(int $companyId, int $userId, int $integrationId, array $assignments): int
    {
        $this->integration($companyId, $integrationId);
        $equipmentById = [];
        foreach ($this->equipment($companyId) as $equipment) {
            $equipmentById[(int) $equipment['id']] = true;
        }
        foreach ($assignments as $equipmentId => $externalUnitId) {
            if (! isset($equipmentById[$equipmentId])) {
                throw new DomainException('Uno de los equipos ya no está activo en esta empresa. Actualizá la pantalla e intentá de nuevo.');
            }
        }

        $availableUnits = [];
        foreach ($this->units($integrationId) as $unit) {
            $availableUnits[$unit['id']] = true;
        }
        foreach ($assignments as $externalUnitId) {
            if ($externalUnitId !== '__unlink__' && ! isset($availableUnits[$externalUnitId])) {
                throw new DomainException('Una de las unidades ya no está disponible en Wialon. Actualizá el listado e intentá de nuevo.');
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $savedCount = 0;
            foreach ($assignments as $equipmentId => $externalUnitId) {
                $link = $this->db->table('equipo_telemetria')
                    ->select('id')
                    ->where('empresa_id', $companyId)
                    ->where('integracion_id', $integrationId)
                    ->where('equipo_id', $equipmentId)
                    ->get()
                    ->getRowArray();

                if ($externalUnitId === '__unlink__') {
                    if ($link !== null) {
                        $this->db->table('equipo_telemetria')
                            ->where('id', (int) $link['id'])
                            ->where('empresa_id', $companyId)
                            ->update(['activo' => 0]);
                    }
                    continue;
                }

                $occupied = $this->db->table('equipo_telemetria')
                    ->select('equipo_id')
                    ->where('empresa_id', $companyId)
                    ->where('integracion_id', $integrationId)
                    ->where('unidad_externa', $externalUnitId)
                    ->get()
                    ->getRowArray();
                if ($occupied !== null && (int) $occupied['equipo_id'] !== $equipmentId) {
                    throw new DomainException('Esa unidad ya figura en otro vínculo de esta cuenta. Revisá la selección antes de guardar.');
                }

                $attributes = [
                    'unidad_externa' => $externalUnitId,
                    'rol' => 'PRINCIPAL',
                    'activo' => 1,
                ];
                if ($link !== null) {
                    $this->db->table('equipo_telemetria')
                        ->where('id', (int) $link['id'])
                        ->where('empresa_id', $companyId)
                        ->update($attributes + ['created_by' => $userId]);
                } else {
                    $this->db->table('equipo_telemetria')->insert($attributes + [
                        'empresa_id' => $companyId,
                        'integracion_id' => $integrationId,
                        'equipo_id' => $equipmentId,
                        'created_at' => $now,
                        'created_by' => $userId,
                    ]);
                }
                $savedCount++;
            }

            if ($this->db->transStatus() === false) {
                throw new DomainException('No se pudieron guardar los vínculos. Probá nuevamente.');
            }
            $this->db->transCommit();

            return $savedCount;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            if ($exception instanceof DomainException) {
                throw $exception;
            }
            throw new DomainException('No se pudieron guardar los vínculos de telemetría. Revisá que cada unidad esté asignada una sola vez.');
        }
    }

    /** @return array<string,mixed> */
    private function integration(int $companyId, int $integrationId): array
    {
        $integration = $this->db->table('integraciones_telemetria')
            ->select('id, empresa_id, proveedor, nombre, activo')
            ->where('id', $integrationId)
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->get()
            ->getRowArray();

        if ($integration === null) {
            throw new DomainException('No encontramos esa cuenta de telemetría en tu empresa.');
        }
        if (strtolower((string) $integration['proveedor']) !== 'wialon') {
            throw new DomainException('La vinculación de unidades todavía está disponible solo para Wialon.');
        }

        return $integration;
    }

    /** @return list<array{id:string,name:string}> */
    private function units(int $integrationId): array
    {
        $credentials = $this->credentials->credentials($integrationId);

        return $this->wialon->listUnits($credentials['endpoint'], $credentials['token']);
    }

    /** @return list<array{id:int,code:string,plate:?string}> */
    private function equipment(int $companyId): array
    {
        $rows = $this->db->table('equipos')
            ->select('id, codigo, patente')
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->orderBy('codigo', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'plate' => ($row['patente'] ?? null) === null ? null : (string) $row['patente'],
        ], $rows);
    }
}
