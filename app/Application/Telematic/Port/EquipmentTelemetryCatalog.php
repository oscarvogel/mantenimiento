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
    /**
     * Equipos con al menos una fuente vinculada, **de una sola empresa**.
     *
     * El recorte por empresa no es opcional: sin él, un operador de un
     * cliente dispararía y publicaría alertas sobre la flota de otro.
     *
     * @return list<CoberturaEquipo>
     */
    public function coveredEquipmentFor(int $companyId): array;
}