<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface ImportReferenceGateway
{
    /** @return array{id:int,codigo:string}|null */
    public function activeBranchByCode(int $companyId, string $code): ?array;

    /** @return array{id:int,nombre:string,controla_km:bool,controla_horas:bool}|null */
    public function activeEquipmentTypeByName(string $name): ?array;

    /** @return array{id:int,nombre:string}|null */
    public function activeBrandByName(int $companyId, string $name): ?array;

    /** @return array{id:int,nombre:string}|null */
    public function activeModelByName(int $companyId, int $brandId, int $typeId, string $name): ?array;

    /** @return array{id:int,sucursal_id:int,controla_km:bool,controla_horas:bool,km_actual:int|null,horas_actuales:string|null}|null */
    public function activeEquipmentByCode(int $companyId, string $code): ?array;

    public function equipmentCodeExists(int $companyId, string $code): bool;

    public function equipmentPlateExists(int $companyId, string $plate): bool;

    public function readingDuplicateExists(
        int $companyId,
        int $equipmentId,
        string $recordedAt,
        ?int $kilometers,
        ?string $hours,
        string $origin,
    ): bool;
}
