<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\ConfigureTelemetryIntegrationResult;
use App\Application\Telematic\Port\TelemetryIntegrationConfigurator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use Throwable;

final class CodeIgniterTelemetryIntegrationConfigurator implements TelemetryIntegrationConfigurator
{
    private const WIALON_ENDPOINT = 'https://hst-api.wialon.com/wialon/ajax.html';

    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function configure(int $companyId, int $userId, string $provider, string $name, string $token): ConfigureTelemetryIntegrationResult
    {
        $units = $this->validateAndListUnits($token);
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

            if ($existing !== null) {
                $integrationId = (int) $existing['id'];
                $this->db->table('integraciones_telemetria')
                    ->where('id', $integrationId)
                    ->where('empresa_id', $companyId)
                    ->update($credentials);
                $this->db->table('equipo_telemetria')
                    ->where('empresa_id', $companyId)
                    ->where('integracion_id', $integrationId)
                    ->update(['activo' => 0]);
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
                $link = $this->db->table('equipo_telemetria')
                    ->select('id')
                    ->where('empresa_id', $companyId)
                    ->where('integracion_id', $integrationId)
                    ->where('equipo_id', $match['equipmentId'])
                    ->get()
                    ->getRowArray();

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

    /** @return list<array{id:string,name:string}> */
    private function validateAndListUnits(string $token): array
    {
        $session = null;
        try {
            $login = $this->call('token/login', ['token' => $token, 'fl' => 1]);
            $session = $login['eid'] ?? null;
            if (! is_string($session) || $session === '') {
                throw new DomainException('Wialon no aceptó el token. Revisá que esté vigente y tenga acceso a las unidades.');
            }

            $result = $this->call('core/search_items', [
                'spec' => [
                    'itemsType' => 'avl_unit',
                    'propName' => 'sys_name',
                    'propValueMask' => '*',
                    'sortType' => 'sys_name',
                ],
                'force' => 1,
                'flags' => 65535,
                'from' => 0,
                'to' => 0,
            ], $session);

            $units = [];
            foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
                $id = $item['id'] ?? null;
                $name = trim((string) ($item['nm'] ?? ''));
                if ($id !== null && $name !== '') {
                    $units[] = ['id' => (string) $id, 'name' => $name];
                }
            }

            if ($units === []) {
                throw new DomainException('El token conectó, pero Wialon no devolvió unidades para asociar.');
            }

            return $units;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DomainException('No se pudo conectar con Wialon. Revisá el token e intentá de nuevo.');
        } finally {
            if (is_string($session) && $session !== '') {
                try {
                    $this->call('core/logout', new \stdClass(), $session);
                } catch (Throwable) {
                }
            }
        }
    }

    /** @param array<string,mixed>|object $params
     *  @return array<string,mixed>
     */
    private function call(string $service, array|object $params, ?string $session = null): array
    {
        $payload = ['svc' => $service, 'params' => json_encode($params, JSON_THROW_ON_ERROR)];
        if ($session !== null) {
            $payload['sid'] = $session;
        }

        $curl = curl_init(self::WIALON_ENDPOINT);
        if ($curl === false) {
            throw new DomainException('No se pudo iniciar la conexión con Wialon.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || (isset($decoded['error']) && (int) $decoded['error'] !== 0)) {
            throw new DomainException('Wialon rechazó la conexión. Revisá el token y sus permisos.');
        }

        return $decoded;
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
