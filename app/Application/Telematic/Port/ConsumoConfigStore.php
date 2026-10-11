<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;

/**
 * Guarda y lee los umbrales de cálculo de consumo de una empresa.
 *
 * El `empresaId` viaja siempre en la firma y no es opcional. Son umbrales que
 * salen en pantalla como si fueran un dato medido, y que un tenant no puede
 * ver los de otro.
 */
interface ConsumoConfigStore
{
    /** @return ConsumoConfigEmpresa|null Null si la empresa nunca configuró nada. */
    public function obtener(int $empresaId): ?ConsumoConfigEmpresa;

    /**
     * Guarda la configuración de una empresa, creando la fila si no existía.
     *
     * @return ConsumoConfigEmpresa Lo que quedó guardado, ya validado.
     */
    public function guardar(ConsumoConfigEmpresa $config): ConsumoConfigEmpresa;
}