<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

/**
 * Concepto del catálogo de gastos y haberes de una empresa.
 *
 * Es un value object: no tiene identidad propia, describe a qué registro
 * aplica (gasto, haber o ambos), cómo se mide (día, unidad o importe fijo) y
 * cuánto vale. Las reglas viven acá porque las comparten dos caminos: el
 * maestro de Viajes y el alta en línea desde el propio viaje, que no debe
 * repetir ni una sola validación.
 */
final readonly class TripConcept
{
    public const CATEGORIA_GASTO = 'GASTO';
    public const CATEGORIA_HABER = 'HABER';
    public const CATEGORIA_AMBOS = 'AMBOS';

    public const UNIDAD_DIA = 'DIA';
    public const UNIDAD_UNIDAD = 'UNIDAD';
    public const UNIDAD_FIJO = 'FIJO';

    public const CATEGORIAS = [self::CATEGORIA_GASTO, self::CATEGORIA_HABER, self::CATEGORIA_AMBOS];
    public const UNIDADES = [self::UNIDAD_DIA, self::UNIDAD_UNIDAD, self::UNIDAD_FIJO];

    public const MAX_NAME_LENGTH = 120;

    private string $name;
    private string $category;
    private string $unit;
    private float $unitAmount;
    private string $currency;

    public function __construct(
        string $name,
        string $category,
        string $unit,
        float $unitAmount,
        string $currency = 'ARS',
        private bool $amountEditable = true,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('El nombre del concepto es obligatorio.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new DomainException('El nombre del concepto no puede tener más de ' . self::MAX_NAME_LENGTH . ' caracteres.');
        }

        $category = strtoupper(trim($category));
        if (! in_array($category, self::CATEGORIAS, true)) {
            throw new DomainException('Elegí si el concepto aplica a gastos, haberes o ambos.');
        }

        $unit = strtoupper(trim($unit));
        if (! in_array($unit, self::UNIDADES, true)) {
            throw new DomainException('Elegí si el concepto se mide por día, por unidad o es un importe fijo.');
        }

        if (! is_finite($unitAmount) || $unitAmount < 0) {
            throw new DomainException('Ingresá un importe unitario válido, mayor o igual a cero.');
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = 'ARS';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda del concepto no es válida.');
        }

        $this->name = $name;
        $this->category = $category;
        $this->unit = $unit;
        $this->unitAmount = $unitAmount;
        $this->currency = $currency;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function unitAmount(): float
    {
        return $this->unitAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function amountEditable(): bool
    {
        return $this->amountEditable;
    }

    public function appliesToExpenses(): bool
    {
        return in_array($this->category, [self::CATEGORIA_GASTO, self::CATEGORIA_AMBOS], true);
    }

    public function appliesToEarnings(): bool
    {
        return in_array($this->category, [self::CATEGORIA_HABER, self::CATEGORIA_AMBOS], true);
    }

    public function isDaily(): bool
    {
        return $this->unit === self::UNIDAD_DIA;
    }
}
