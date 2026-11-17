<?php

declare(strict_types=1);

use App\Application\Trips\AddTripLegCommand;
use App\Application\Trips\TripLegInput;
use App\Domain\Trips\TripLegLiquidationBasis;
use PHPUnit\Framework\TestCase;

/**
 * La carga del tramo separa facturación y acuerdo con el chofer (#533).
 */
final class TripLegLiquidationBasisInputTest extends TestCase
{
    /** @return array<string,mixed> */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'viaje_id' => 55,
            'origen' => 'Rosario',
            'destino' => 'Córdoba',
            'cliente_id' => 3,
            'localidad_origen_id' => 101,
            'localidad_destino_id' => 202,
            'moneda' => 'ARS',
            'flete_facturacion' => '2000000',
        ], $overrides);
    }

    public function testPerTonneComputesTheBaseAndIgnoresAWrittenAmount(): void
    {
        $command = AddTripLegCommand::fromInput($this->row([
            'modo_liquidacion' => 'POR_TONELADA',
            'toneladas_liquidacion' => '30',
            'precio_unitario_liquidacion' => '45000',
            'flete_liquidacion' => '999',
        ]));

        self::assertSame(TripLegLiquidationBasis::POR_TONELADA, $command->liquidationBasis()->mode());
        self::assertSame(1_350_000.0, $command->liquidation()->amount());
        self::assertSame(1_350_000.0, $command->liquidation()->amountArs());
    }

    public function testAgreedAmountDoesNotRequireTonnesOrUnitPrice(): void
    {
        $command = AddTripLegCommand::fromInput($this->row([
            'modo_liquidacion' => 'MONTO_ACORDADO',
            'flete_liquidacion' => '1250000',
            'toneladas_liquidacion' => '30',
        ]));

        self::assertSame(1_250_000.0, $command->liquidation()->amount());
        self::assertNull($command->liquidationBasis()->tonnes());
        self::assertNull($command->liquidationBasis()->unitPrice());
    }

    public function testInvoicePerTonneAndDriverPerAgreedAmountAreIndependent(): void
    {
        // El tramo lleva 30 t operativas y factura 2.000.000; al chofer se le
        // pactó un global de 1.250.000.
        $command = AddTripLegCommand::fromInput($this->row([
            'toneladas' => '30',
            'modo_liquidacion' => 'MONTO_ACORDADO',
            'flete_liquidacion' => '1250000',
        ]));

        self::assertSame(30.0, $command->tonnes);
        self::assertSame(2_000_000.0, $command->invoice()->amount());
        self::assertSame(1_250_000.0, $command->liquidation()->amount());
    }

    public function testFormsWithoutModeKeepTheHistoricalMeaning(): void
    {
        $command = AddTripLegCommand::fromInput($this->row(['flete_liquidacion' => '800000']));

        self::assertSame(TripLegLiquidationBasis::MONTO_ACORDADO, $command->liquidationBasis()->mode());
        self::assertSame(800_000.0, $command->liquidation()->amount());
    }

    public function testPerTonneNeedsBothTonnesAndPrice(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('toneladas y el precio por tonelada');

        AddTripLegCommand::fromInput($this->row([
            'modo_liquidacion' => 'POR_TONELADA',
            'toneladas_liquidacion' => '30',
        ]));
    }

    public function testPerTonneMayStayEmptyWhileTheTripIsOpen(): void
    {
        $command = AddTripLegCommand::fromInput($this->row(['modo_liquidacion' => 'POR_TONELADA']));

        self::assertNull($command->liquidation()->amount());
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(DomainException::class);

        AddTripLegCommand::fromInput($this->row(['modo_liquidacion' => 'POR_KM']));
    }

    public function testPerTonneInDollarsWithInvoiceInPesosUsesTheLiquidationRate(): void
    {
        $command = AddTripLegCommand::fromInput($this->row([
            'modo_liquidacion' => 'POR_TONELADA',
            'toneladas_liquidacion' => '30',
            'precio_unitario_liquidacion' => '40',
            'moneda_liquidacion' => 'USD',
            'cotizacion_liquidacion' => '1250',
        ]));

        self::assertSame('ARS', $command->invoice()->currency());
        self::assertSame(1_200.0, $command->liquidation()->amount());
        self::assertSame(1_500_000.0, $command->liquidation()->amountArs());
    }

    public function testCreateTripLegsReadTheSameAgreement(): void
    {
        $input = TripLegInput::fromInput($this->row([
            'modo_liquidacion' => 'POR_TONELADA',
            'toneladas_liquidacion' => '30',
            'precio_unitario_liquidacion' => '45000',
        ]));

        self::assertSame(1_350_000.0, $input->liquidation()->amount());
        self::assertTrue($input->liquidationBasis()->isPerTonne());
    }
}
