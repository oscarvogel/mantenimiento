<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport\Port;

/**
 * Puerto de escritura de ítems de repuesto detectados en un documento.
 *
 * El puerto expone únicamente `appendToWorkOrder()`: no hay actualización ni
 * borrado, de modo que los repuestos importados se agregan y nunca se pisan.
 * La implementación debe:
 * - scopear cada fila por `empresa_id` y rechazar órdenes de otra empresa;
 * - deduplicar por (orden, descripción, cantidad) en la propia sentencia SQL,
 *   no comparando en memoria, para que reintentar la importación del mismo
 *   documento no duplique ítems.
 */
interface ImportedOrderPartWriter
{
    /**
     * @param list<array<string,mixed>> $detected Filas normalizadas del analizador
     *        de documentos (description, quantity, unit, source_text).
     * @return int Cantidad de líneas efectivamente agregadas a la OT.
     */
    public function appendToWorkOrder(int $companyId, int $workOrderId, string $serviceDate, array $detected): int;
}
