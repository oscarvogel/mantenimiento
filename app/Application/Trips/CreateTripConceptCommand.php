<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

/**
 * Datos con los que se da de alta un concepto del catálogo de Viajes.
 *
 * El comando traduce la entrada cruda (formulario o JSON) a valores
 * tipados; las reglas del concepto viven en {@see \App\Domain\Trips\TripConcept}.
 */
final readonly class CreateTripConceptCommand
{
    public function __construct(
        private string $name,
        private string $category,
        private string $unit,
        private float $unitAmount,
        private string $currency,
        private bool $amountEditable,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $amount = str_replace(',', '.', trim((string) ($input['importe_unitario'] ?? '')));
        if ($amount === '' || ! is_numeric($amount)) {
            throw new DomainException('Ingresá un importe unitario válido, mayor o igual a cero.');
        }

        // Si el dato no viene, el importe se puede editar al cargarlo: es el
        // comportamiento por defecto del maestro de Viajes.
        $editable = true;
        if (array_key_exists('permite_editar_importe', $input)) {
            $editable = (string) $input['permite_editar_importe'] === '1';
        }

        return new self(
            trim((string) ($input['nombre'] ?? '')),
            strtoupper(trim((string) ($input['categoria'] ?? ''))),
            strtoupper(trim((string) ($input['unidad'] ?? ''))),
            (float) $amount,
            strtoupper(trim((string) ($input['moneda'] ?? 'ARS'))),
            $editable,
        );
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
}
