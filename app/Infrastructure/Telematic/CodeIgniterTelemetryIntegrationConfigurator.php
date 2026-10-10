<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\ConfigureTelemetryIntegrationResult;
use App\Application\Telematic\TelemetryUnitLinkRetention;
use App\Application\Telematic\Port\TelemetryIntegrationConfigurator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use Throwable;

final class CodeIgniterTelemetryIntegrationConfigurator implements TelemetryIntegrationConfigurator
{
    private const WIALON_ENDPOINT = 'https://hst-api.wialon.com/wialon/ajax.html';

    private readonly BaseConnection $db;
    private readonly WialonUnitCatalogClient $wialon;

    public function __construct(?BaseConnection $db = null, ?WialonUnitCatalogClient $wialon = null)
    {
        $this->db = $db ?? Database::connect();
        $this->wialon = $wialon ?? new WialonUnitCatalogClient();
    }

    public function configure(int $companyId, int $userId, string $provider, string $name, string $token): ConfigureTelemetryIntegrationResult
    {
        $units = $this->wialon->listUnits(self::WIALON_ENDPOINT, $token);
        $equipment = $this->db->table('equipos')
            ->select('id, codigo, patente')
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        [$matches, $unmatched] = $this->matchEquipment($equipment, $units);
        $now = date('Y-m-d H:i:s');

        try {
            $encryptedToken = base64_encode(service('encrypter')->encrypt($token));
        } catch (Throwable) {
            throw new DomainException('No se pudo cifrar el token. Verificá la clave de cifrado de la aplicación.');
        }

        $this->db->transBegin();
        try {
            $existing = $this->db->table('integraciones_telemetria')
                ->select('id')
                ->where('empresa_id', $companyId)
                ->where('proveedor', $provider)
                ->where('nombre', $name)
                ->get()
                ->getRowArray();

            $credentials = [
                'endpoint' => self::WIALON_ENDPOINT,
                'token_cifrado' => $encryptedToken,
                'activo' => 1,
                'updated_at' => $now,
                'updated_by' => $userId,
            ];

            $currentLinks = $existing === null ? [] : $this->db->table('equipo_telemetria')
                ->select('id, equipo_id, unidad_externa, activo')
                ->where('empresa_id', $companyId)
                ->where('integracion_id', (int) $existing['id'])
                ->get()
                ->getResultArray();
            $availableUnitIds = array_fill_keys(array_column($units, 'id'), true);
            $obsoleteUnitIds = TelemetryUnitLinkRetention::obsoleteUnitIds($units, $currentLinks);
            $equipmentCodes = [];
            foreach ($equipment as $row) {
                $equipmentCodes[(int) $row['id']] = (string) $row['codigo'];
            }
            $alreadyLinkedEquipmentCodes = [];
            foreach ($currentLinks as $currentLink) {
                $equipmentId = (int) $currentLink['equipo_id'];
                if ((int) $currentLink['activo'] === 1
                    && isset($availableUnitIds[(string) $currentLink['unidad_externa']])
                    && isset($equipmentCodes[$equipmentId])) {
                    $alreadyLinkedEquipmentCodes[] = $equipmentCodes[$equipmentId];
                }
            }
            $unmatched = array_values(array_filter(
                $unmatched,
                static fn (string $code): bool => ! in_array($code, $alreadyLinkedEquipmentCodes, true),
            ));

            if ($existing !== null) {
                $integrationId = (int) $existing['id'];
                $this->db->table('integraciones_telemetria')
                    ->where('id', $integrationId)
                    ->where('empresa_id', $companyId)
                    ->update($credentials);
                if ($obsoleteUnitIds !== []) {
                    $this->db->table('equipo_telemetria')
                        ->where('empresa_id', $companyId)
                        ->where('integracion_id', $integrationId)
                        ->whereIn('unidad_externa', $obsoleteUnitIds)
                        ->where('activo', 1)
                        ->update(['activo' => 0]);
                }
            } else {
                $this->db->table('integraciones_telemetria')->insert($credentials + [
                    'empresa_id' => $companyId,
                    'proveedor' => $provider,
                    'nombre' => $name,
                    'created_at' => $now,
                    'created_by' => $userId,
                ]);
                $integrationId = (int) $this->db->insertID();
            }

            $linked = 0;
            foreach ($matches as $match) {
                $link = null;
                foreach ($currentLinks as $currentLink) {
                    if ((int) $currentLink['equipo_id'] === $match['equipmentId']) {
                        $link = $currentLink;
                        break;
                    }
                }

                if ($link !== null && (int) $link['activo'] === 1 && isset($availableUnitIds[(string) $link['unidad_externa']])) {
                    // La asociación elegida por una persona tiene prioridad sobre una coincidencia automática.
                    $linked++;
                    continue;
                }

                if ($link !== null) {
                    $this->db->table('equipo_telemetria')->where('id', (int) $link['id'])->update([
                        'unidad_externa' => $match['externalId'],
                        'rol' => 'PRINCIPAL',
                        'activo' => 1,
                    ]);
                    $linked++;
                    continue;
                }

                $externalLink = $this->db->table('equipo_telemetria')
                    ->select('id')
                    ->where('integracion_id', $integrationId)
                    ->where('unidad_externa', $match['externalId'])
                    ->get()
                    ->getRowArray();

                if ($externalLink !== null) {
                    $unmatched[] = $match['equipmentCode'];
                    continue;
                }

                $this->db->table('equipo_telemetria')->insert([
                    'empresa_id' => $companyId,
                    'integracion_id' => $integrationId,
                    'equipo_id' => $match['equipmentId'],
                    'unidad_externa' => $match['externalId'],
                    'rol' => 'PRINCIPAL',
                    'activo' => 1,
                    'created_at' => $now,
                    'created_by' => $userId,
                ]);
                $linked++;
            }

            if ($this->db->transStatus() === false) {
                throw new DomainException('No se pudo guardar la integración. Probá nuevamente.');
            }
            $this->db->transCommit();

            return new ConfigureTelemetryIntegrationResult($integrationId, $linked, $unmatched);
        } catch (Throwable $exception) {
            $this->db->transRollback();
            if ($exception instanceof DomainException) {
                throw $exception;
            }
            throw new DomainException('No se pudo guardar la integración de telemetría.');
        }
    }

    /** @param list<array<string,mixed>> $equipment
     *  @param list<array{id:string,name:string}> $units
     *  @return array{list<array{equipmentId:int,externalId:string,equipmentCode:string}>,list<string>}
     */
    private function matchEquipment(array $equipment, array $units): array
    {
        $byName = [];
        foreach ($units as $unit) {
            $key = strtoupper(trim($unit['name']));
            $byName[$key][] = $unit['id'];
        }

        $equipmentByName = [];
        foreach ($equipment as $row) {
            $key = strtoupper(trim((string) (($row['patente'] ?? '') ?: ($row['codigo'] ?? ''))));
            if ($key !== '') {
                $equipmentByName[$key][] = $row;
            }
        }

        $matches = [];
        $unmatched = [];
        foreach ($equipment as $row) {
            $label = (string) $row['codigo'];
            $key = strtoupper(trim((string) (($row['patente'] ?? '') ?: ($row['codigo'] ?? ''))));
            if ($key === '' || count($equipmentByName[$key] ?? []) !== 1 || count($byName[$key] ?? []) !== 1) {
                $unmatched[] = $label;
                continue;
            }
            $matches[] = [
                'equipmentId' => (int) $row['id'],
                'externalId' => (string) $byName[$key][0],
                'equipmentCode' => $label,
            ];
        }

        return [$matches, $unmatched];
    }
}
