<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Lectura y borrado del histórico de muestras de una empresa.
 *
 * Leer y borrar están en el mismo puerto a propósito: separar el "qué se va a
 * borrar" del "borro esto" abre la puerta a que se purgue sobre un conjunto
 * distinto del que se respaldó, y un respaldo que no corresponde con lo
 * borrado no es un respaldo.
 */
interface HistoricoMuestras
{
    /**
     * Capturas anterior al corte, ordenadas.
     *
     * @return list<MuestraEquipo>
     */
    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array;

    /**
     * @return int Cuántas filas se eliminaron.
     */
    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int;
}