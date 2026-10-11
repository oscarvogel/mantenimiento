<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use DomainException;

/**
 * La telemetría está configurada pero no se puede leer en esta empresa.
 *
 * Es distinta de un fallo puntual del proveedor: aquí no hay nada que
 * actualizar, o no hay integraciones, y un operador tiene que enterarse con un
 * mensaje claro en vez de una pantalla vacía.
 */
final class TelemetriaNoDisponible extends DomainException
{
}