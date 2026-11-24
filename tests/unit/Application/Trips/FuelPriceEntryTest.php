<?php

declare(strict_types=1);

use App\Application\Trips\FuelPriceEntry;
use PHPUnit\Framework\TestCase;

/**
 * Lectura de los importes que completa el operador.
 *
 * Una fila vacía es una carga que sigue pendiente; un importe que no es un
 * número es un error que hay que mostrar, no algo que se ignore.
 */
final class FuelPriceEntryTest extends TestCase
{
    public function testReadsTheAmountsWithTheirCurrencyAndRate(): void
    {
        $entries = FuelPriceEntry::fromPost([
            'importe' => [90 => '150000', 91 => '98000,50'],
            'moneda' => [90 => 'ARS', 91 => 'BRL'],
            'cotizacion' => [90 => '', 91 => '290'],
        ]);

        self::assertCount(2, $entries);
        self::assertSame(90, $entries[0]->fuelId);
        self::assertSame(150000.0, $entries[0]->amount);
        self::assertSame('ARS', $entries[0]->currency);
        self::assertSame(1.0, $entries[0]->exchangeRate);
        self::assertSame(91, $entries[1]->fuelId);
        self::assertSame(98000.5, $entries[1]->amount);
        self::assertSame('BRL', $entries[1]->currency);
        self::assertSame(290.0, $entries[1]->exchangeRate);
    }

    public function testLeavesOutTheRowsWithoutAmount(): void
    {
        $entries = FuelPriceEntry::fromPost([
            'importe' => [90 => '', 91 => '98000'],
            'moneda' => [90 => 'ARS', 91 => 'ARS'],
        ]);

        self::assertCount(1, $entries);
        self::assertSame(91, $entries[0]->fuelId);
    }

    public function testRejectsAnAmountThatIsNotANumber(): void
    {
        $this->expectException(DomainException::class);

        FuelPriceEntry::fromPost(['importe' => [90 => 'mucho']]);
    }

    public function testRejectsAnAmountThatIsNotGreaterThanZero(): void
    {
        $this->expectException(DomainException::class);

        FuelPriceEntry::fromPost(['importe' => [90 => '-5']]);
    }

    public function testRejectsACurrencyThatIsNotACurrency(): void
    {
        $this->expectException(DomainException::class);

        FuelPriceEntry::fromPost(['importe' => [90 => '1000'], 'moneda' => [90 => 'PESOS']]);
    }

    public function testRejectsARateThatIsNotGreaterThanZero(): void
    {
        $this->expectException(DomainException::class);

        FuelPriceEntry::fromPost(['importe' => [90 => '1000'], 'moneda' => [90 => 'USD'], 'cotizacion' => [90 => '0']]);
    }

    public function testWithoutAmountsThereIsNothingToComplete(): void
    {
        self::assertSame([], FuelPriceEntry::fromPost([]));
        self::assertSame([], FuelPriceEntry::fromPost(['importe' => 'no es una lista']));
    }
}
