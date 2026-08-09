<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\AssetImportData;
use App\Application\Importations\Port\AssetImportGateway;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;
use RuntimeException;

final class CodeIgniterAssetImportGateway implements AssetImportGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isDuplicate(int $companyId, string $code): bool
    {
        return $this->database->table('equipos')->where('empresa_id', $companyId)
            ->where('codigo', $code)->where('deleted_at', null)->countAllResults() > 0;
    }

    public function import(AssetImportData $data): int
    {
        $branchValid = $this->database->table('sucursales')->where('id', $data->branchId)
            ->where('empresa_id', $data->companyId)->where('estado', 1)->where('deleted_at', null)->countAllResults() > 0;
        $typeRow = $this->database->table('tipos_equipo')->select('id, nombre, controla_km, controla_horas')
            ->where('id', $data->equipmentTypeId)->where('activo', 1)->get()->getRowArray();
        if (! $branchValid || $typeRow === null) {
            throw new DomainException('La sucursal o el tipo dejaron de estar disponibles.');
        }
        if ($data->brandId !== null) {
            $brandValid = $this->database->table('marcas')->where('id', $data->brandId)
                ->where('empresa_id', $data->companyId)->where('activo', 1)->countAllResults() > 0;
            if (! $brandValid) {
                throw new DomainException('La marca dejo de estar disponible.');
            }
        }
        if ($data->modelId !== null) {
            $modelValid = $this->database->table('modelos')->where('id', $data->modelId)
                ->where('empresa_id', $data->companyId)->where('marca_id', $data->brandId)
                ->where('tipo_equipo_id', $data->equipmentTypeId)->where('activo', 1)->countAllResults() > 0;
            if (! $modelValid) {
                throw new DomainException('El modelo dejo de estar disponible para la marca y tipo seleccionados.');
            }
        }
        if ($this->isDuplicate($data->companyId, $data->code)) {
            throw new DomainException('El equipo ya existe.');
        }
        $equipment = Equipment::create(
            $data->companyId,
            $data->branchId,
            new EquipmentType(
                (int) $typeRow['id'], (string) $typeRow['nombre'],
                (bool) $typeRow['controla_km'], (bool) $typeRow['controla_horas'],
            ),
            $data->code,
            $data->plate,
            new DateTimeImmutable($data->registeredAt),
            $data->notes,
            $data->brandId,
            $data->modelId,
            $data->year,
            $data->chassis,
            $data->engine,
        );
        $now = date('Y-m-d H:i:s');
        $this->database->table('equipos')->insert([
            'empresa_id' => $equipment->companyId(), 'sucursal_id' => $equipment->branchId(),
            'tipo_equipo_id' => $equipment->type()->id(), 'codigo' => $equipment->code(),
            'patente' => $equipment->plate(), 'marca_id' => $equipment->brandId(), 'modelo_id' => $equipment->modelId(),
            'anio' => $equipment->year(), 'chasis' => $equipment->chassis(), 'motor' => $equipment->engine(),
            'estado' => $equipment->status(), 'fecha_alta' => $equipment->registeredAt()->format('Y-m-d'), 'observaciones' => $equipment->notes(),
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $data->actorUserId, 'updated_by' => $data->actorUserId,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo persistir el equipo importado.');
        }
        return $id;
    }
}
