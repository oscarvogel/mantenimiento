<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

/**
 * Lee la última instantánea conocida de un equipo, por fuente.
 *
 * Es un puerto y no un repositorio de dominio porque no hay tabla de
 * telemetría: esto es una proyección de estado actual, no un agregado con
 * reglas propias.
 */
interface TelemetrySnapshotReader
{
    /**
     * Instantáneas de un equipo dentro de una empresa.
     *
     * El recorte por empresa va en la consulta, no después: el dato de
     * telemetría es del mismo alcance que el resto de la ficha y no debe
     * existir una forma de leerlo de otra empresa.
     *
     * @return list<array<string,mixed>>
     */
    public function forEquipment(int $companyId, int $equipmentId): array;
}