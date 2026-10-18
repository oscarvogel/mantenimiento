<?php

declare(strict_types=1);

use App\Infrastructure\TripImports\LegacyTripRowMapper;
use PHPUnit\Framework\TestCase;

final class LegacyTripRowMapperTest extends TestCase
{
    private LegacyTripRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyTripRowMapper();
    }

    public function testMapsTripHeaderAndTrimsLegacyPadding(): void
    {
        $trip = $this->mapper->trip([
            'cuit_empresa' => '23-28059786-4',
            'dominio' => 'AC 532 DD ',
            'numero_viaje' => '1',
            'fecha_salida' => '2022-01-13',
            'legajo_chofer' => '11000000000  ',
            'observaciones_1' => 'ARREGLADO CON EL CHOFER ',
            'observaciones_2' => '   ',
            'observaciones_3' => '\\N',
            'km_salida' => '100',
            'km_regreso' => '200',
            'fecha_regreso' => '2022-01-14',
            'contabilizado' => '0',
            'fecha_contabilizacion' => '\\N',
            'porcentaje_chofer' => '14.00',
            'marcar' => '1',
        ]);

        self::assertSame([], $trip->errors);
        self::assertSame('23-28059786-4|AC 532 DD|1', $trip->key);
        self::assertSame('AC 532 DD', $trip->domain);
        self::assertSame('11000000000', $trip->data['legajo_chofer_origen']);
        self::assertSame('ARREGLADO CON EL CHOFER', $trip->data['observaciones_1']);
        self::assertNull($trip->data['observaciones_3']);
        self::assertSame(100, $trip->data['km_salida']);
        self::assertSame('14.00', $trip->data['porcentaje_chofer']);
        self::assertSame(1, $trip->data['marcar']);
    }

    public function testWarnsWhenReturnDateIsBeforeDeparture(): void
    {
        $trip = $this->mapper->trip($this->tripRow([
            'fecha_salida' => '2022-05-26',
            'fecha_regreso' => '2018-04-08',
        ]));

        self::assertSame([], $trip->errors);
        self::assertNotEmpty($trip->warnings);
        self::assertSame('fecha_regreso', $trip->warnings[0]['field']);
    }

    public function testBlocksTripWithoutValidDepartureDate(): void
    {
        $trip = $this->mapper->trip($this->tripRow(['fecha_salida' => '2022-02-31']));

        self::assertNotEmpty($trip->errors);
        self::assertSame('fecha_salida', $trip->errors[0]['field']);
    }

    public function testMapsTramosAndRenamesLegacyColumns(): void
    {
        $child = $this->mapper->child('t06_origenes_destinos_por_viaje', [
            'cuit_empresa' => '23-28059786-4',
            'dominio' => 'AC 532 DD ',
            'numero_viaje' => '1',
            'item' => '1',
            'codigo_origen_destino' => '10',
            'cantidad_kilometros' => '150.50',
            'toneladas' => '12.00',
            'precio' => '1.5000',
            'importe' => '225.7500',
            'descripcion_origen' => 'ROSARIO',
            'descripcion_destino' => 'CORDOBA',
            'observaciones1' => 'OBS UNO',
            'observaciones2' => '\\N',
            'observaciones3' => '\\N',
            'observaciones4' => '\\N',
            'remito' => 'R-1',
            'cobrado' => '0',
            'codigo_alternativo' => 'ALT',
            'codigo_cliente' => 'CLI-1',
            'importe_inicial' => '200.0000',
            'porcentaje_facturacion' => '100.00',
            'fecha_salida' => '2022-01-13',
            'fecha_llegada' => '2022-01-14',
        ]);

        self::assertSame([], $child->errors);
        self::assertSame('viaje_tramos', $child->table);
        self::assertSame('23-28059786-4|AC 532 DD|1', $child->tripKey);
        self::assertSame('OBS UNO', $child->data['observaciones_1']);
        self::assertSame('CLI-1', $child->data['codigo_cliente_origen']);
        self::assertSame('225.7500', $child->data['importe']);
    }

    public function testMapsAccountCurrentTramosWithSubItem(): void
    {
        $child = $this->mapper->child('t12_cuenta_corriente_origenes_destinos_por_viaje', [
            'cuit_empresa' => '23-28059786-4',
            'dominio' => 'IUU5G88',
            'numero_viaje' => '5',
            'item' => '2',
            'sub_item' => '3',
            'tipo_comprobante' => 'FACTURA',
            'punto_venta' => '0001',
            'numero_comprobante' => '123',
            'importe' => '10.5000',
            'fecha' => '2022-08-12',
        ]);

        self::assertSame([], $child->errors);
        self::assertSame('viaje_cuenta_corriente_tramos', $child->table);
        self::assertSame(3, $child->data['sub_item']);
        self::assertSame('FACTURA', $child->data['tipo_comprobante']);
    }

    public function testOrphanChildWithoutKeyIsDetected(): void
    {
        $child = $this->mapper->child('t07_cargas_combustible_por_viaje', [
            'cuit_empresa' => '',
            'dominio' => '',
            'numero_viaje' => '0',
            'item' => '0',
            'litros' => '10.00',
            'importe' => '100.0000',
        ]);

        self::assertSame('', $child->tripKey);
        self::assertNotEmpty($child->errors);
    }

    /** @param array<string,string> $overrides @return array<string,string> */
    private function tripRow(array $overrides): array
    {
        return array_merge([
            'cuit_empresa' => '23-28059786-4',
            'dominio' => 'AF 117 ZK ',
            'numero_viaje' => '11',
            'fecha_salida' => '2022-05-26',
            'legajo_chofer' => '2000000000',
            'observaciones_1' => '\\N',
            'observaciones_2' => '\\N',
            'observaciones_3' => '\\N',
            'km_salida' => '1000',
            'km_regreso' => '2000',
            'fecha_regreso' => '2022-05-30',
            'contabilizado' => '0',
            'fecha_contabilizacion' => '\\N',
            'porcentaje_chofer' => '14.00',
            'marcar' => '0',
        ], $overrides);
    }
}
