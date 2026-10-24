<?php

declare(strict_types=1);

namespace App\Application\Facturacion;

/**
 * Comprobante que se presenta ante ARCA para su anulacion.
 *
 * Un comprobante autorizado no se anula en el sistema: se pide la baja ante
 * ARCA y se emite la nota de credito correspondiente. El motivo viaja para que
 * la traza quede completa.
 */
final readonly class ComprobanteParaAnular
{
    public function __construct(
        public int $companyId,
        public string $tipoComprobante,
        public int $puntoVenta,
        public int $numero,
        public string $motivo,
    ) {
    }
}
