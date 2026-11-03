<?php

declare(strict_types=1);

use App\Presentation\Trips\TripCostEntryForm;
use PHPUnit\Framework\TestCase;

final class TripCostEntryFormTest extends TestCase
{
    public function testNormalizesOnlyKnownEntryTypes(): void
    {
        self::assertSame(TripCostEntryForm::FUEL, TripCostEntryForm::normalizeType(' FUEL '));
        self::assertSame(TripCostEntryForm::EXPENSE, TripCostEntryForm::normalizeType('expense'));
        self::assertSame(TripCostEntryForm::CASH, TripCostEntryForm::normalizeType('cash'));
        self::assertNull(TripCostEntryForm::normalizeType('otros'));
        self::assertNull(TripCostEntryForm::normalizeType(null));
    }

    public function testKeepsOnlyAllowedPostValues(): void
    {
        $values = TripCostEntryForm::valuesFromPost(TripCostEntryForm::FUEL, [
            'fecha' => '2026-11-03T10:30',
            'litros' => '100',
            'importe' => '50000',
            'moneda' => 'ARS',
            'tramo_id' => '3',
            'accion' => 'otro',
            'repetir' => ['fecha'],
            'csrf_test_name' => 'abc',
        ]);

        self::assertSame('2026-11-03T10:30', $values['fecha']);
        self::assertSame('100', $values['litros']);
        self::assertSame('3', $values['tramo_id']);
        self::assertArrayNotHasKey('accion', $values);
        self::assertArrayNotHasKey('repetir', $values);
        self::assertArrayNotHasKey('csrf_test_name', $values);
    }

    public function testBuildsRepeatQueryOnlyForSelectedFieldsAndIncludesCurrencyRate(): void
    {
        $query = TripCostEntryForm::repeatQuery(TripCostEntryForm::EXPENSE, [
            'fecha' => '2026-11-03T10:30',
            'moneda' => 'USD',
            'cotizacion' => '1200',
            'tramo_id' => '3',
            'categoria_id' => '1',
            'pagado_por' => 'CHOFER',
            'medio_pago_id' => '2',
            'comprobante' => 'A-1',
            'repetir' => ['fecha', 'moneda'],
        ]);

        self::assertSame([
            'repetir_fecha' => '2026-11-03T10:30',
            'repetir_moneda' => 'USD',
            'repetir_cotizacion' => '1200',
        ], $query);
    }

    public function testReadsRepeatedValuesFromQuery(): void
    {
        $values = TripCostEntryForm::repeatValues(TripCostEntryForm::CASH, [
            'cargar' => 'cash',
            'repetir_fecha' => '2026-11-03T10:30',
            'repetir_moneda' => 'USD',
            'repetir_cotizacion' => '1200',
            'repetir_tipo' => 'ANTICIPO',
        ]);

        self::assertSame([
            'fecha' => '2026-11-03T10:30',
            'moneda' => 'USD',
            'cotizacion' => '1200',
            'tipo' => 'ANTICIPO',
        ], $values);
    }

    public function testBuildsStateWithOldValuesRepeatValuesAndEditingId(): void
    {
        $state = TripCostEntryForm::state(
            TripCostEntryForm::FUEL,
            ['fecha' => '2026-11-03T10:30', 'importe' => '50000'],
            ['repetir_moneda' => 'ARS'],
            9,
        );

        self::assertSame(TripCostEntryForm::FUEL, $state['type']);
        self::assertSame('repeat', $state['mode']);
        self::assertSame('50000', $state['values']['importe']);
        self::assertSame('ARS', $state['repeat']['moneda']);
        self::assertSame(9, $state['editing_id']);
    }
}
