<?php

declare(strict_types=1);

namespace App\Domain\Importations;

use DomainException;

enum ImportType: string
{
    case EQUIPOS = 'EQUIPOS';
    case UNIDADES_TRANSPORTE = 'UNIDADES_TRANSPORTE';
    case LECTURAS = 'LECTURAS';
    case VENCIMIENTOS = 'VENCIMIENTOS';
    case BIBLIOTECA_PREVENTIVA = 'BIBLIOTECA_PREVENTIVA';

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return match ($this) {
            self::EQUIPOS => ['sucursal_codigo', 'tipo_equipo', 'codigo', 'fecha_alta'],
            // La fuente real de TSA es una planilla multihoja que el lector adapta
            // al formato canónico de equipos antes de validar estas columnas.
            self::UNIDADES_TRANSPORTE => ['sucursal_codigo', 'tipo_equipo', 'codigo', 'fecha_alta'],
            self::LECTURAS => ['equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro', 'origen'],
            self::VENCIMIENTOS => ['equipo_codigo', 'tipo_vencimiento', 'fecha_vencimiento'],
            self::BIBLIOTECA_PREVENTIVA => [],
        };
    }

    /** @return list<string> */
    public function templateHeaders(): array
    {
        return match ($this) {
            self::EQUIPOS => [
                'sucursal_codigo', 'tipo_equipo', 'codigo', 'patente', 'marca', 'modelo',
                'anio', 'chasis', 'motor', 'fecha_alta', 'observaciones',
            ],
            self::UNIDADES_TRANSPORTE => [
                'sucursal_codigo', 'tipo_equipo', 'codigo', 'patente', 'marca', 'modelo',
                'anio', 'chasis', 'motor', 'fecha_alta', 'observaciones',
            ],
            self::LECTURAS => [
                'equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro',
                'origen', 'observaciones',
            ],
            self::VENCIMIENTOS => [
                'equipo_codigo', 'tipo_vencimiento', 'fecha_vencimiento', 'fecha_emision',
                'numero_documento', 'observaciones',
            ],
            self::BIBLIOTECA_PREVENTIVA => [],
        };
    }

    public static function parse(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value)))
            ?? throw new DomainException('El tipo de importacion debe ser EQUIPOS, UNIDADES_TRANSPORTE, LECTURAS, VENCIMIENTOS o BIBLIOTECA_PREVENTIVA.');
    }
}
