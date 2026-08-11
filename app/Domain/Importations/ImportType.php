<?php

declare(strict_types=1);

namespace App\Domain\Importations;

use DomainException;

enum ImportType: string
{
    case EQUIPOS = 'EQUIPOS';
    case LECTURAS = 'LECTURAS';
    case BIBLIOTECA_PREVENTIVA = 'BIBLIOTECA_PREVENTIVA';

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return match ($this) {
            self::EQUIPOS => ['sucursal_codigo', 'tipo_equipo', 'codigo', 'fecha_alta'],
            self::LECTURAS => ['equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro', 'origen'],
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
            self::LECTURAS => [
                'equipo_codigo', 'fecha_lectura', 'kilometraje', 'horometro',
                'origen', 'observaciones',
            ],
            self::BIBLIOTECA_PREVENTIVA => [],
        };
    }

    public static function parse(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value)))
            ?? throw new DomainException('El tipo de importacion debe ser EQUIPOS, LECTURAS o BIBLIOTECA_PREVENTIVA.');
    }
}
