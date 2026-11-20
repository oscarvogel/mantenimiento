<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

/**
 * Resultado de comparar los litros declarados con los que vio el sensor.
 *
 * `SinDatosSuficientes` y `EnCurso` no son veredictos: son la forma de no
 * emitir uno. Un hueco de telemetría o un sensor parcial no pueden terminar en
 * "el chofer declaró de más".
 */
enum EstadoConciliacion: string
{
    case Conciliado = 'CONCILIADO';
    case DeclaradoSupera = 'DECLARADO_SUPERA';
    case DeclaradoMenor = 'DECLARADO_MENOR';
    case SinDatosSuficientes = 'SIN_DATOS_SUFICIENTES';
    /** Las cargas se declaran al volver: mientras el viaje sigue, no se compara. */
    case EnCurso = 'EN_CURSO';

    public function esVeredicto(): bool
    {
        return in_array($this, [self::Conciliado, self::DeclaradoSupera, self::DeclaradoMenor], true);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Conciliado => 'Coincide',
            self::DeclaradoSupera => 'Declarado mayor que lo medido',
            self::DeclaradoMenor => 'Declarado menor que lo medido',
            self::SinDatosSuficientes => 'Sin datos suficientes',
            self::EnCurso => 'Viaje en curso',
        };
    }
}
