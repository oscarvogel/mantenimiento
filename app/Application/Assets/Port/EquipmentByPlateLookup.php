<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

/**
 * Resuelve la patente de una unidad al equipo registrado.
 *
 * Lo consumen Viajes, Neumaticos y Facturacion: los tres identifican las
 * unidades por patente porque es lo que viene del ERP, de las planillas y de
 * los comprobantes. Assets es el dueno de `equipos` y de la relacion
 * tractor-acoplado, asi que es el contexto que responde.
 *
 * Solo se expone informacion plana con el scope de empresa ya resuelto. Quien
 * consume el puerto no ve el modelo ORM ni consulta la tabla.
 */
interface EquipmentByPlateLookup
{
    /**
     * @return array{id:int,branchId:int,label:string,plate:string,code:string}|null
     *         Null cuando la patente no existe en la empresa o el equipo esta dado de baja.
     */
    public function equipment(int $companyId, string $plate): ?array;
}
