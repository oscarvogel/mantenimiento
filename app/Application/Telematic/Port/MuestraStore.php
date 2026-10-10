<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\MuestraEquipo;

/**
 * Guarda capturas de telemetría en el histórico.
 *
 * La tabla es append-only y la única clave es la captura:
 * `(integracion_id, unidad_externa, observada_en)`. Insertar dos veces la misma
 * captura **no es un error**, es una corrida repetida, y por eso este puerto
 * no lanza excepción: informa cuántas filas quedaron nuevas.
 *
 * Esa distinción no es un detalle. En MySQL la deduplicación se hace con
 * `INSERT IGNORE`, que ignora el duplicado en silencio, así que saber si se
 * insertó exige leer `affectedRows`. Devolver `void` obligaría al caso de uso a
 * suponer, y esa suposición es falsa justo cuando el cron se reintenta, que es
 * cuando más importa saberlo.
 */
interface MuestraStore
{
    /**
     * @param MuestraEquipo $muestra Ya viene validada por el dominio.
     *
     * @return int 1 si la captura se guardó, 0 si ya estaba y fue ignorada.
     */
    public function guardar(MuestraEquipo $muestra): int;
}