<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface PreventiveLibraryReferenceGateway
{
    /** @return array<string,mixed>|null */
    public function serviceByCode(string $code): ?array;

    /** @return array<string,mixed>|null */
    public function taskByCode(string $code): ?array;

    /** @return array<string,mixed>|null */
    public function materialByCodes(string $serviceCode, string $itemCode): ?array;

    /** @return array{id:int,nombre:string}|null */
    public function activeEquipmentTypeByName(string $name): ?array;

    /** @return array<string,mixed>|null */
    public function companyTemplateByCode(int $companyId, string $code): ?array;

    /** @return array<string,mixed>|null */
    public function templateItemByCodes(int $companyId, string $templateCode, string $serviceCode): ?array;
}
