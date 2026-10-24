<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\ConfigureTelemetryIntegrationResult;
use App\Application\Telematic\Port\AutotracApi;
use App\Application\Telematic\Port\AutotracIntegrationConfigurator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use Throwable;

final class CodeIgniterAutotracIntegrationConfigurator implements AutotracIntegrationConfigurator
{
    private const ENDPOINT = 'https://aapi3.autotrac-online.com.br/aticapi';

    private readonly BaseConnection $db;

    public function __construct(private readonly AutotracApi $api, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function configure(int $companyId, int $userId, string $name, array $credentials): ConfigureTelemetryIntegrationResult
    {
        $accounts = $this->api->accounts($credentials);
        $accountCode = (int) $credentials['accountCode'];
        $valid = false;
        foreach ($accounts as $account) {
            if ((int) $account['Code'] === $accountCode) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            throw new DomainException('La cuenta elegida no está disponible con esas credenciales. Probá la conexión otra vez y seleccioná una cuenta de la lista.');
        }

        // Verify vehicle access before persisting the company's integration.
        $vehicles = $this->api->vehicles($credentials);
        if ($vehicles === []) {
            throw new DomainException('La cuenta Autotrac conectó, pero no devolvió vehículos. Confirmá los equipos autorizados para la ATIC API.');
        }
        $availableUnitIds = [];
        foreach ($vehicles as $vehicle) {
            if (is_numeric($vehicle['Code'] ?? null)) {
                $availableUnitIds[(string) (int) $vehicle['Code']] = true;
            }
        }
        try {
            $encrypted = base64_encode(service('encrypter')->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR)));
        } catch (Throwable) {
            throw new DomainException('No se pudieron proteger los datos de acceso de la ATIC API. Revisá la clave de cifrado de la aplicación.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $existing = $this->db->table('integraciones_telemetria')
                ->select('id')
                ->where('empresa_id', $companyId)
                ->where('proveedor', 'autotrac')
                ->where('nombre', $name)
                ->get()
                ->getRowArray();
            $attributes = [
                'endpoint' => self::ENDPOINT,
                'token_cifrado' => $encrypted,
                'activo' => 1,
                'ultimo_error' => null,
                'consecutivos_fallidos' => 0,
                'updated_at' => $now,
                'updated_by' => $userId,
            ];
            if ($existing !== null) {
                $id = (int) $existing['id'];
                $this->db->table('integraciones_telemetria')->where('id', $id)->where('empresa_id', $companyId)->update($attributes);
                $currentLinks = $this->db->table('equipo_telemetria')
                    ->select('id, unidad_externa')
                    ->where('empresa_id', $companyId)
                    ->where('integracion_id', $id)
                    ->where('activo', 1)
                    ->get()
                    ->getResultArray();
                $obsoleteLinkIds = [];
                foreach ($currentLinks as $link) {
                    if (! isset($availableUnitIds[(string) $link['unidad_externa']])) {
                        $obsoleteLinkIds[] = (int) $link['id'];
                    }
                }
                if ($obsoleteLinkIds !== []) {
                    $this->db->table('equipo_telemetria')
                        ->where('empresa_id', $companyId)
                        ->where('integracion_id', $id)
                        ->whereIn('id', $obsoleteLinkIds)
                        ->update(['activo' => 0]);
                }
            } else {
                $this->db->table('integraciones_telemetria')->insert($attributes + [
                    'empresa_id' => $companyId,
                    'proveedor' => 'autotrac',
                    'nombre' => $name,
                    'created_at' => $now,
                    'created_by' => $userId,
                ]);
                $id = (int) $this->db->insertID();
            }
            if ($this->db->transStatus() === false) {
                throw new DomainException('No se pudo guardar la integración Autotrac. Intentá de nuevo.');
            }
            $this->db->transCommit();

            return new ConfigureTelemetryIntegrationResult($id, 0, []);
        } catch (Throwable $exception) {
            $this->db->transRollback();
            if ($exception instanceof DomainException) {
                throw $exception;
            }
            throw new DomainException('No se pudo guardar la integración Autotrac.');
        }
    }
}
