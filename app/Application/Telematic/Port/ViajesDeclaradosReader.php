<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\Conciliacion\ViajeDeclarado;
use DateTimeImmutable;

/**
 * Contrato de lectura que Viajes le ofrece a Telemetría: los viajes operativos
 * con su tractor y las cargas de combustible que declaró el chofer.
 *
 * Telemetría no consulta las tablas de Viajes: las lee el adaptador, que vive
 * del lado de Viajes (`App\Infrastructure\Trips`). Registrado en
 * `docs/CONTRATOS_ENTRE_CONTEXTOS.md`.
 *
 * Reglas del contrato:
 *
 * - La empresa es obligatoria. `$sucursalIds` en null significa todas las de
 *   la empresa; una lista vacía no devuelve nada.
 * - Solo viajes `EN_CURSO` y `FINALIZADO`, con salida real, que se superpongan
 *   con el período. Un cancelado no tiene nada que conciliar.
 * - Los litros son la suma de las cargas vigentes (sin `deleted_at`).
 * - El tractor es el de la asignación `TRACTOR` del viaje; si hubo más de uno,
 *   el primero asignado.
 */
interface ViajesDeclaradosReader
{
    /**
     * @param list<int>|null $sucursalIds
     *
     * @return list<ViajeDeclarado> Ordenados por salida, del más reciente al más viejo.
     */
    public function viajesEntre(
        int $empresaId,
        ?array $sucursalIds,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array;
}
