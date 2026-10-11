<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;

/**
 * Traduce una fila de `telematia_muestras` a una `MuestraEquipo`.
 *
 * Vive aparte porque hay más de un lector: el que arma la serie para calcular
 * consumo y el que lee lo vencido para respaldarlo y borrarlo. Si cada uno
 * tuviera su propia versión del mapeo, una corrección en uno dejaría al otro
 * leyendo distinto — y el respaldo contendría filas que el cálculo no puede
 * reconstruir.
 *
 * Sin credenciales y sin consulta: sólo recibe el array que devolvió la base.
 */
final class FilaAMuestra
{
    /** @param array<string,mixed> $fila */
    public function __invoke(array $fila): MuestraEquipo
    {
        $observada = new DateTimeImmutable((string) $fila['observada_en']);
        $registrada = new DateTimeImmutable((string) ($fila['registrada_en'] ?? $fila['observada_en']));

        $instantanea = new InstantaneaEquipo(
            $observada,
            $this->posicion($fila, $observada),
            $this->entero($fila, 'kilometraje'),
            $this->entero($fila, 'horas_decimales'),
            $this->booleano($fila['motor_encendido'] ?? null),
            $this->booleano($fila['ralenti_activo'] ?? null),
            $this->decimal($fila, 'voltaje'),
            $this->decimal($fila, 'combustible_litros'),
        );

        return new MuestraEquipo(
            (int) $fila['empresa_id'],
            (int) $fila['integracion_id'],
            (int) $fila['equipo_id'],
            (string) $fila['unidad_externa'],
            (string) $fila['proveedor'],
            $instantanea,
            $registrada,
        );
    }

    /**
     * Sin coordenadas no hay posición. Y (0,0) es Null Island, no un lugar: un
     * GPS sin fix escribe ceros, y tomarlos como posición pondría el camión en
     * el golfo de Guinea sin avisar.
     *
     * @param array<string,mixed> $fila
     */
    private function posicion(array $fila, DateTimeImmutable $observada): ?Posicion
    {
        if ($fila['latitud'] === null || $fila['longitud'] === null) {
            return null;
        }

        return new Posicion(
            (float) $fila['latitud'],
            (float) $fila['longitud'],
            $this->decimal($fila, 'velocidad_kmh'),
            $this->entero($fila, 'rumbo'),
            $this->decimal($fila, 'altitud_m'),
            $this->entero($fila, 'satelites'),
            $observada,
        );
    }

    /** @param array<string,mixed> $fila */
    private function entero(array $fila, string $columna): ?int
    {
        return isset($fila[$columna]) ? (int) $fila[$columna] : null;
    }

    /** @param array<string,mixed> $fila */
    private function decimal(array $fila, string $columna): ?float
    {
        return isset($fila[$columna]) ? (float) $fila[$columna] : null;
    }

    private function booleano(mixed $valor): ?bool
    {
        return $valor === null ? null : ((int) $valor === 1);
    }
}