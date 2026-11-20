<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DateTimeImmutable;
use DomainException;

/**
 * Documento que respalda el nacimiento de la deuda: el memo autorizado, la
 * factura emitida (registrada a mano mientras no haya emisión electrónica) o la
 * liquidación interna no fiscal del cierre comercial aprobado.
 */
final readonly class ChargeDocument
{
    /** Tipos de factura que se registran a mano. */
    public const INVOICE_TYPES = ['FACTURA_A', 'FACTURA_B', 'FACTURA_C', 'FACTURA_E', 'OTRO'];

    private ?string $invoiceType;
    private ?string $number;

    public function __construct(
        private string $modality,
        private DateTimeImmutable $date,
        ?string $number = null,
        ?string $invoiceType = null,
    ) {
        $number = $number === null ? null : trim($number);
        $number = $number === '' ? null : $number;
        if ($number !== null && mb_strlen($number) > 60) {
            throw new DomainException('El número del documento admite hasta 60 caracteres.');
        }

        switch ($modality) {
            case CustomerCharge::MEMO_AUTORIZADO:
                if ($number === null) {
                    throw new DomainException('Indicá el número o la referencia del memo autorizado.');
                }
                $invoiceType = null;
                break;
            case CustomerCharge::FACTURA_EMITIDA:
                if ($number === null) {
                    throw new DomainException('Indicá el punto de venta y el número de la factura.');
                }
                $invoiceType = strtoupper(trim((string) $invoiceType));
                if (! in_array($invoiceType, self::INVOICE_TYPES, true)) {
                    throw new DomainException('Elegí el tipo de factura.');
                }
                break;
            case CustomerCharge::CIERRE_COMERCIAL_APROBADO:
            case CustomerCharge::SALDO_INICIAL:
                // La liquidación interna numera el sistema; el saldo inicial puede
                // llevar una referencia libre (por ejemplo, el resumen de origen).
                $invoiceType = null;
                break;
            default:
                throw new DomainException('Elegí cómo se formaliza el cobro del tramo.');
        }

        $this->number = $number;
        $this->invoiceType = $invoiceType;
    }

    public function modality(): string
    {
        return $this->modality;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function number(): ?string
    {
        return $this->number;
    }

    public function invoiceType(): ?string
    {
        return $this->invoiceType;
    }
}
