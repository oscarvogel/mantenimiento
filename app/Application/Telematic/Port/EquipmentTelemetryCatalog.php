<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\CoberturaEquipo;

/**
 * Equipos con al menos una fuente de telemetría vinculada, con sus fuentes.
 *
 * El caso de uso recibe la cobertura ya agrupada: la agregación por equipo es
 * parte de la regla de negocio y no de la consulta, aunque el adaptador la
 * resuelva en un solo join para no traer la misma fila N veces.
 */
interface EquipmentTelemetryCatalog
{
    /** @return list<CoberturaEquipo> Sólo equipos con al menos una fuente vinculada. */
    public function coveredEquipment(): array;
}