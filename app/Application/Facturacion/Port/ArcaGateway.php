<?php

declare(strict_types=1);

namespace App\Application\Facturacion\Port;

use App\Application\Facturacion\AutorizacionResult;
use App\Application\Facturacion\ComprobanteParaAnular;
use App\Application\Facturacion\ComprobanteParaAutorizar;

/**
 * Puerta hacia la autoridad de aplicacion.
 *
 * El dominio y los casos de uso de Facturacion no conocen el protocolo, el
 * certificado ni el web service: define esta interfaz y el adaptador vive en
 * infraestructura. Asi el circuito completo se puede probar sin llamar a
 * ARCA, y la autorizacion deja de ser un detalle de transporte.
 *
 * Un rechazo se devuelve como resultado, no como excepcion. Solo una falla de
 * transporte o de certificado es una excepcion, y en ese caso la peticion
 * queda para reintento.
 */
interface ArcaGateway
{
    public function authorize(ComprobanteParaAutorizar $comprobante): AutorizacionResult;

    public function status(string $cae): AutorizacionResult;

    public function cancel(ComprobanteParaAnular $comprobante): AutorizacionResult;
}
