<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripConcept;

/**
 * Catálogo de conceptos de gasto y haber de una empresa.
 */
interface TripConceptCatalog
{
    /**
     * Indica si la empresa ya tiene un concepto con ese nombre.
     *
     * La comparación no distingue mayúsculas ni acentos, que es como el
     * índice único de la tabla trata los nombres duplicados.
     */
    public function existsByName(int $companyId, string $name): bool;

    /** Guarda el concepto y devuelve el identificador con el que quedó. */
    public function create(int $companyId, TripConcept $concept, int $userId): int;
}
