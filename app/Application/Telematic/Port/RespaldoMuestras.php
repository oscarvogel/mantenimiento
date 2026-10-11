<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\MuestraEquipo;

/**
 * Respaldo de las capturas que se van a purgar.
 *
 * `escribir` y `verificar` están separadas a propósito. Escribir un archivo y
 * seguir sin mirarlo no es un respaldo: un disco lleno, un límite de tamaño o
 * un contenedor montado en sólo lectura producen un archivo vacío con código de
 * salida cero, y el borrado ya ocurrió. Por eso la purga verifica que el
 * archivo contenga exactamente las filas que espera antes de borrar nada.
 */
interface RespaldoMuestras
{
    /**
     * @param list<MuestraEquipo> $muestras
     *
     * @return int Cuántas filas se escribieron.
     */
    public function escribir(string $archivo, array $muestras): int;

    /**
     * @param int $esperadas Filas que deberían estar en el archivo.
     */
    public function verificar(string $archivo, int $esperadas): bool;
}