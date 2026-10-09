<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\InstantaneaRegistrada;

/**
 * Guarda la última instantánea conocida por fuente.
 *
 * Es estado actual, no histórico: una fila por integración y unidad externa,
 * que se sobrescribe en cada corrida.
 */
interface TelemetrySnapshotStore
{
    public function save(InstantaneaRegistrada $snapshot): void;

    /**
     * Marca como ausentes las instantáneas de las fuentes que no vinieron en
     * esta corrida. La fila se conserva: el último lugar conocido sigue siendo
     * un dato, y borrarlo dejaría la ficha vacía en vez de desactualizada.
     *
     * @param list<string> $observedExternalIds
     *
     * @return int Cuántas filas quedaron marcadas.
     */
    public function markAbsent(int $integrationId, array $observedExternalIds, ?string $now): int;
}