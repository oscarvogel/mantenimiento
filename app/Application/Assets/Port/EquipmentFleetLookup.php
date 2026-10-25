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

    /**
     * Semirremolques vigentes enganchados a los equipos indicados.
     *
     * La relacion entre tractor y semirremolque es temporal: solo se devuelven
     * las que siguen abiertas. Un semirremolque no puede estar enganchado a
     * dos tractores a la vez, pero un tractor puede llevar varios semirremolques
     * (bitren), y en ese caso se devuelven todos.
     *
     * La relacion se acepta en las dos direcciones porque la pantalla de la
     * ficha deja crearla desde el tractor o desde el semirremolque. De las dos
     * partes solo se devuelve la que es semirremolque.
     *
     * @param list<int> $equipmentIds
     *
     * @return array<int, list<array{id:int,label:string,plate:string,code:string}>>
     *         Semirremolques por equipo. Los equipos sin semirremolque no aparecen.
     */
    public function activeTrailers(int $companyId, array $equipmentIds): array;
}
