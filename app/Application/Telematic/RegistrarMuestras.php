<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\MuestraStore;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Traduce una instantánea registrada a una muestra histórica y la guarda.
 *
 * Existe como caso de uso aparte y no como una línea dentro del ciclo de ingesta
 * porque es el único punto donde una señal de proveedor se vuelve un hecho
 * persistido con empresa. Ese salto de contexto es donde está el riesgo: si
 * algún día la muestra se arma en otro lado, es fácil olvidar el `empresa_id` y
 * dejar el histórico fuera del alcance por tenant.
 *
 * Reutiliza el `InstantaneaEquipo` que ya viene validado en lugar de volver a
 * leer sus métricas: la instantánea salió del proveedor, ya le movió a
 * `anomalias` lo que no podía aceptar, y acá no hay nada que volver a decidir.
 *
 * La sucursal se descarta a propósito. Una muestra pertenece a una empresa y a
 * un equipo; el alcance por sucursal lo aplica la lectura, que sí la necesita
 * para no mostrarle a un responsable de una planta el recorrido de otra.
 */
final readonly class RegistrarMuestras
{
    public function __construct(private MuestraStore $store)
    {
    }

    /**
     * @return int 1 si la captura era nueva, 0 si ya estaba en el histórico.
     */
    public function registrar(InstantaneaRegistrada $snapshot): int
    {
        return $this->store->guardar(new MuestraEquipo(
            $snapshot->companyId(),
            $snapshot->integrationId(),
            $snapshot->equipmentId(),
            $snapshot->externalUnitId(),
            $snapshot->provider(),
            $snapshot->snapshot(),
            new DateTimeImmutable($snapshot->registeredAt()),
        ));
    }
}