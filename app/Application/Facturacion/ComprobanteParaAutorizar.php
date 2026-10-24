<?php

declare(strict_types=1);

namespace App\Application\Facturacion;

use DateTimeImmutable;

/**
 * Comprobante que se presenta ante ARCA para su autorizacion.
 *
 * Los importes se envian ya calculados: el calculo de netos es del contexto y
 * ARCA no recalcula. Los renglones usan el mismo criterio que en la
 * especificacion: bonificacion, IVA y retenciones aplicadas antes del total.
 */
final readonly class ComprobanteParaAutorizar
{
    /**
     * @param array<int,array{descripcion:string,cantidad:float,precioUnitario:float,importe:float,iva:float}> $items
     */
    public function __construct(
        public int $companyId,
        public string $tipoComprobante,
        public int $puntoVenta,
        public int $numero,
        public DateTimeImmutable $fecha,
        public string $clienteCuit,
        public array $items,
        public float $subtotal,
        public float $bonificacion,
        public float $iva,
        public float $impuestos,
        public float $total,
    ) {
    }
}
