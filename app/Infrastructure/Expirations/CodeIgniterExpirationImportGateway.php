<?php

declare(strict_types=1);

namespace App\Infrastructure\Expirations;

use App\Application\Importations\ExpirationImportData;
use App\Application\Importations\Port\ExpirationImportGateway;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;

/**
 * Adaptador de persistencia para los vencimientos que llegan desde una
 * importación. La validación de alcance vuelve a ejecutarse aquí porque el
 * borrador puede confirmarse después de que cambien catálogos o accesos.
 */
final class CodeIgniterExpirationImportGateway implements ExpirationImportGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isDuplicate(ExpirationImportData $data): bool
    {
        $typeId = $this->findTypeId($data->companyId, $data->type);
        if ($typeId === null) {
            return false;
        }

        return $this->database->table('vencimientos')
            ->where('empresa_id', $data->companyId)
            ->where('equipo_id', $data->equipmentId)
            ->where('tipo_vencimiento_id', $typeId)
            ->where('fecha_vencimiento', $data->expirationDate)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    public function import(ExpirationImportData $data): int
    {
        $equipment = $this->database->table('equipos')
            ->select('id, sucursal_id')
            ->where('empresa_id', $data->companyId)
            ->where('id', $data->equipmentId)
            ->where('sucursal_id', $data->branchId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($equipment === null) {
            throw new DomainException('El equipo ya no existe, está inactivo o cambió de sucursal.');
        }

        $typeId = $this->findTypeId($data->companyId, $data->type);
        if ($typeId === null) {
            $now = date('Y-m-d H:i:s');
            $this->database->table('tipos_vencimiento')->insert([
                'empresa_id' => $data->companyId,
                'nombre' => $this->canonicalTypeName($data->type),
                'aplica_a' => 'EQUIPO',
                'dias_aviso_previo' => 30,
                'requiere_documento' => 0,
                'activo' => 1,
                'created_by' => $data->actorUserId,
                'updated_by' => $data->actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $typeId = (int) $this->database->insertID();
            if ($typeId <= 0) {
                // Otra confirmación pudo crear el mismo catálogo en paralelo.
                $typeId = $this->findTypeId($data->companyId, $data->type);
            }
        }
        if ($typeId === null || $typeId <= 0) {
            throw new RuntimeException('No se pudo preparar el tipo de vencimiento.');
        }

        $existing = $this->database->table('vencimientos')
            ->select('id')
            ->where('empresa_id', $data->companyId)
            ->where('equipo_id', $data->equipmentId)
            ->where('tipo_vencimiento_id', $typeId)
            ->where('fecha_vencimiento', $data->expirationDate)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table('vencimientos')->insert([
            'empresa_id' => $data->companyId,
            'sucursal_id' => $data->branchId,
            'tipo_vencimiento_id' => $typeId,
            'sujeto_tipo' => 'EQUIPO',
            'equipo_id' => $data->equipmentId,
            'fecha_emision' => $data->issueDate,
            'fecha_vencimiento' => $data->expirationDate,
            'numero_documento' => $data->documentNumber,
            'observaciones' => $data->notes,
            'origen' => 'IMPORTACION',
            'importacion_id' => $data->importId,
            'activo' => 1,
            'created_by' => $data->actorUserId,
            'updated_by' => $data->actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo persistir el vencimiento importado.');
        }
        return $id;
    }

    private function findTypeId(int $companyId, string $name): ?int
    {
        $canonical = $this->canonicalTypeName($name);
        $rows = $this->database->table('tipos_vencimiento')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->get()->getResultArray();
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) $row['nombre'])) === mb_strtolower($canonical)) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    private function canonicalTypeName(string $name): string
    {
        return match (mb_strtoupper(trim($name))) {
            'POLIZA', 'PÓLIZA' => 'Póliza',
            'VTV', 'ITV' => 'VTV / ITV',
            'SENASA' => 'SENASA',
            'CRVL' => 'CRVL',
            default => trim($name),
        };
    }
}
