<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

/**
 * Resuelve una unidad de la flota para el circuito operativo.
 *
 * Lo consumen los contextos que planifican trabajo sobre la flota: Viajes al
 * asignar tractor, acoplado y chofer, y en adelante Neumaticos y Facturacion.
 * Responde Assets, que es el dueno de `equipos` y del catalogo de tipos.
 *
 * Devuelve datos planos con el scope de empresa ya resuelto. Quien consume el
 * puerto no ve el modelo ORM ni consulta la tabla.
 */
interface EquipmentFleetLookup
{
    /**
     * @return array{id:int,branchId:int,label:string,plate:string,code:string,tractor:bool,trailer:bool}|null
     *         Null cuando el equipo no existe en la empresa o esta dado de baja.
     */
    public function equipment(int $companyId, int $equipmentId): ?array;

    /**
     * Resuelve por patente, que es como llega la unidad desde el ERP.
     *
     * @return array{id:int,branchId:int,label:string,plate:string,code:string,tractor:bool,trailer:bool}|null
     */
    public function equipmentByPlate(int $companyId, string $plate): ?array;
}
