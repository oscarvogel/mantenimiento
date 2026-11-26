<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Moneda de una operación de la cuenta corriente (#588).
 *
 * La moneda original de la deuda se conserva durante todo su ciclo de vida: una
 * deuda en USD sigue siendo una deuda en USD, diga lo que diga la cotización.
 * Esta clase solo normaliza y valida el código; el listado de monedas que la
 * empresa habilita es un catálogo de infraestructura, no una regla de dominio.
 */
final readonly class Currency
{
    public const ARS = 'ARS';

    /**
     * Monedas iniciales aprobadas para la cuenta corriente (#588).
     *
     * No son un catálogo cerrado: habilitarlas en el maestro de monedas de
     * viajes habilita la operación en esa moneda. Esta constante documenta el
     * conjunto con el que arranca el módulo.
     */
    public const INITIAL = ['ARS', 'USD', 'BRL'];

    private function __construct(private string $code)
    {
    }

    public static function fromCode(string $code): self
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new DomainException('La moneda debe tener tres letras.');
        }

        return new self($code);
    }

    public static function ars(): self
    {
        return new self(self::ARS);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function isArs(): bool
    {
        return $this->code === self::ARS;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
