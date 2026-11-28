<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\ChargeAllocation;
use App\Domain\CustomerAccounts\Currency;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\PaymentImputation;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * El reparto del valor histórico y la diferencia de cambio (#588).
 *
 * Son reglas puras: no dependen de la base de datos ni del framework.
 */
final class ChargeAllocationTest extends TestCase
{
    public function testReparteElValorHistoricoEnProporcionAlImporte(): void
    {
        // Documento de USD 2.800 a 1.500: ARS 4.200.000.
        $allocation = new ChargeAllocation(
            chargeOriginalMinor: 280_000,
            chargeArsMinor: 420_000_000,
            allocatedOriginalMinor: 0,
            allocatedArsMinor: 0,
        );

        self::assertSame(280_000, $allocation->pendingOriginalMinor());
        // Imputar la mitad se lleva la mitad del valor histórico.
        self::assertSame(150_000_000, $allocation->allocate(100_000));
        // Imputar todo se lleva el valor histórico completo.
        self::assertSame(420_000_000, $allocation->allocate(280_000));
    }

    public function testLaUltimaImputacionSeLlevaElResiduoExacto(): void
    {
        // Un documento partido en tres: las partes tienen que sumar el documento
        // sin dejar centavos sueltos.
        $total = 1_000_000;
        $value = 333_333_333;

        $first = new ChargeAllocation($total, $value, 0, 0);
        $share = $first->allocate(333_333);
        self::assertSame(111_111_000, $share);

        $second = new ChargeAllocation($total, $value, 333_333, $share);
        $share2 = $second->allocate(333_333);
        self::assertSame(111_111_000, $share2);

        $third = new ChargeAllocation($total, $value, 666_666, $share + $share2);
        $share3 = $third->allocate(333_334);
        self::assertSame($value - $share - $share2, $share3);
        self::assertSame($value, $share + $share2 + $share3);
    }

    public function testNoReparteSobreUnDocumentoYaSaldado(): void
    {
        $allocation = new ChargeAllocation(100_000, 150_000_000, 100_000, 150_000_000);

        self::assertSame(0, $allocation->pendingOriginalMinor());
        self::assertSame(0, $allocation->allocate(1));
    }

    public function testRechazaUnDocumentoSinImporte(): void
    {
        $this->expectException(DomainException::class);
        new ChargeAllocation(0, 0, 0, 0);
    }

    public function testRechazaLoCobradoQueSuperaElDocumento(): void
    {
        $this->expectException(DomainException::class);
        new ChargeAllocation(100_000, 150_000_000, 100_001, 0);
    }
}
