<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Domain\Telematic\MensajeHistorico;
use App\Infrastructure\Telematic\WialonHistoricalMessageMapper;
use PHPUnit\Framework\TestCase;

final class WialonHistoricalMessageMapperTest extends TestCase
{
    public function testMapeaSoloDatosPresentesEnElMensaje(): void
    {
        $mensaje = (new WialonHistoricalMessageMapper())->map(
            [
                't' => 1791633720,
                'tp' => 'ud',
                'pos' => ['y' => -33.1, 'x' => -68.8, 'z' => 900, 's' => 12, 'c' => 180, 'sc' => 8],
                'p' => ['mileage' => 12345.6, 'engine_hours' => 456.7],
                'sensors' => [
                    '1' => ['value' => 321.5],
                    '2' => ['value' => 1],
                    '3' => ['value' => 1],
                ],
            ],
            $this->definitions(),
        );

        self::assertInstanceOf(MensajeHistorico::class, $mensaje);
        self::assertSame(1791633720, $mensaje->observadaEn()->getTimestamp());
        self::assertSame(321.5, $mensaje->combustibleLitros());
        self::assertSame(12346, $mensaje->kilometraje());
        self::assertSame(4567, $mensaje->horasDecimales());
        self::assertTrue($mensaje->motorEncendido());
        self::assertTrue($mensaje->ralentiActivo());
        self::assertSame(-33.1, $mensaje->posicion()->latitude());
        self::assertSame(12.0, $mensaje->posicion()->speedKmh());
    }

    public function testValoresAusentesNoSeCompletanDesdeOtrosMensajes(): void
    {
        $mensaje = (new WialonHistoricalMessageMapper())->map(
            ['t' => 1791633720, 'tp' => 'ud', 'p' => [], 'sensors' => []],
            $this->definitions(),
        );

        self::assertInstanceOf(MensajeHistorico::class, $mensaje);
        self::assertNull($mensaje->combustibleLitros());
        self::assertNull($mensaje->kilometraje());
        self::assertNull($mensaje->horasDecimales());
        self::assertNull($mensaje->motorEncendido());
        self::assertNull($mensaje->ralentiActivo());
        self::assertNull($mensaje->posicion());
    }

    public function testLecturasImposiblesQuedanAusentesEnVezDeContaminarLaSerie(): void
    {
        $mensaje = (new WialonHistoricalMessageMapper())->map(
            [
                't' => 1791633720,
                'tp' => 'ud',
                'p' => ['mileage' => -1, 'engine_hours' => -1],
                'sensors' => ['1' => ['value' => -348201.0]],
            ],
            $this->definitions(),
        );

        self::assertNull($mensaje->combustibleLitros());
        self::assertNull($mensaje->kilometraje());
        self::assertNull($mensaje->horasDecimales());
    }

    public function testIgnoraTiposDeMensajeQueNoSeanDatos(): void
    {
        self::assertNull((new WialonHistoricalMessageMapper())->map(
            ['t' => 1791633720, 'tp' => 'sms', 'p' => []],
            $this->definitions(),
        ));
    }

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            '1' => ['id' => 1, 'n' => 'COMBUSTIBLE', 't' => 'fuel level', 'm' => 'l'],
            '2' => ['id' => 2, 'n' => 'ESTADO MOTOR', 't' => 'engine operation', 'm' => ''],
            '3' => ['id' => 3, 'n' => 'RALENTI', 't' => 'digital', 'm' => ''],
        ];
    }
}
