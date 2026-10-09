<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Infrastructure\Telematic\WialonSnapshotMapper;
use PHPUnit\Framework\TestCase;

/**
 * Fija la traducción del vocabulario de Wialon al nuestro.
 *
 * El fixture usa un equipo real: IVECO 440 AF081MJ, con los dos tanques de
 * combustible y los sensores de batería, motor y ralentí.
 */
final class WialonSnapshotMapperTest extends TestCase
{
    /** Definiciones que devuelve core/search_items para esa unidad. */
    private function definiciones(): array
    {
        return [
            '1' => ['id' => 1, 'n' => 'BATERIA', 't' => 'voltage', 'm' => 'V'],
            '2' => ['id' => 2, 'n' => 'ESTADO MOTOR', 't' => 'engine operation', 'm' => 'Encendido/Apagado'],
            '3' => ['id' => 3, 'n' => 'ralenti activado', 't' => 'digital', 'm' => 'Encendido/Apagado'],
            '5' => ['id' => 5, 'n' => 'COMBUSTIBLE', 't' => 'fuel level', 'm' => 'l'],
            '6' => ['id' => 6, 'n' => 'COMBUSTIBLE T2', 't' => 'custom', 'm' => 'l'],
            '7' => ['id' => 7, 'n' => 'COMBUSTIBLE T1', 't' => 'custom', 'm' => 'l'],
        ];
    }

    /** Valores que devuelve unit/calc_last con flags=15 para esa unidad. */
    private function valores(): array
    {
        return [
            'i' => 28396292,
            'mileage' => ['value' => 487499.7069149875, 'format' => ['value' => '487499.71 km']],
            'engine_hours' => ['value' => 4062.549722222222, 'format' => ['value' => '4062.55 h']],
            'pos' => [
                'y' => -33.0944383, 'x' => -68.880015, 'c' => 216,
                'z' => ['value' => 955.4], 's' => ['value' => 0], 'sc' => 25,
                't' => 1791563963,
            ],
            'sensors' => [
                '1' => ['value' => 24.507, 'format' => ['value' => '24.51 V']],
                '2' => ['value' => 0, 'format' => ['value' => 'Apagado']],
                '3' => ['value' => 0, 'format' => ['value' => 'Apagado']],
                '5' => ['value' => 660.4761926209239, 'format' => ['value' => '660.48 l']],
                '6' => ['value' => 166.449643944705, 'format' => ['value' => '166.45 l']],
                '7' => ['value' => 494.02654867621897, 'format' => ['value' => '494.03 l']],
            ],
        ];
    }

    public function testTraduceLaRespuestaCompletaAlVocabularioPropio(): void
    {
        $instantanea = (new WialonSnapshotMapper())->map($this->valores(), $this->definiciones());

        self::assertSame(487500, $instantanea->kilometraje(), 'El odómetro se redondea a entero.');
        self::assertSame(40625, $instantanea->horasDecimales(), 'Las horas van en décimas, como el resto del sistema.');
        self::assertSame(24.507, $instantanea->voltaje());
        self::assertFalse($instantanea->motorEncendido());
        self::assertFalse($instantanea->ralentiActivo());
        self::assertSame(660.4761926209239, $instantanea->combustibleLitros(), 'El proveedor ya entrega el total calibrado en litros.');
    }

    public function testLaPosicionSeTraduceCompleta(): void
    {
        $posicion = (new WialonSnapshotMapper())->map($this->valores(), $this->definiciones())->posicion();

        self::assertNotNull($posicion);
        self::assertEqualsWithDelta(-33.0944383, $posicion->latitude(), 0.0000001);
        self::assertEqualsWithDelta(-68.880015, $posicion->longitude(), 0.0000001);
        self::assertSame(216, $posicion->course());
        self::assertSame(955.4, $posicion->altitudeMeters());
        self::assertSame(25, $posicion->satellites());
        self::assertSame(0.0, $posicion->speedKmh());
    }

    public function testLaPosicionSinMarcaDeTiempoVieneDeSearchItems(): void
    {
        // unit/calc_last trae las coordenadas pero SIN `t`. La marca de tiempo
        // sólo aparece en core/search_items. Si el adaptador no une ambas, se
        // pierde la posicion entera y la fecha queda siendo la de la corrida.
        $valores = $this->valores();
        unset($valores['pos']['t']);

        $posicionDesdeItems = [
            'y' => -33.0944383, 'x' => -68.880015, 'c' => 216,
            'z' => ['value' => 955.4], 's' => ['value' => 29], 'sc' => 25,
            't' => 1791563963,
        ];

        $instantanea = (new WialonSnapshotMapper())->map($valores, $this->definiciones(), $posicionDesdeItems);

        self::assertNotNull($instantanea->posicion(), 'Sin la union de las dos respuestas no hay posicion.');
        self::assertSame(29.0, $instantanea->posicion()->speedKmh());
        // Se compara el instante, no su formato: la zona horaria depende del
        // entorno y no es lo que se quiere fijar aqui.
        self::assertSame(1791563963, $instantanea->observadaEn()->getTimestamp());
    }

    public function testSinPosicionNiMarcaDeTiempoCaeAlAhora(): void
    {
        $valores = $this->valores();
        unset($valores['pos']);

        $instantanea = (new WialonSnapshotMapper())->map($valores, []);

        self::assertNull($instantanea->posicion());
        self::assertSame(487500, $instantanea->kilometraje());
    }

    public function testLosTanquesSueltosQuedanComoMedidasAdicionalesConSuNombreOriginal(): void
    {
        $instantanea = (new WialonSnapshotMapper())->map($this->valores(), $this->definiciones());

        $etiquetas = array_map(static fn ($m): string => $m->etiqueta(), $instantanea->sensoresAdicionales());

        self::assertContains('COMBUSTIBLE T1', $etiquetas);
        self::assertContains('COMBUSTIBLE T2', $etiquetas);
        self::assertNotContains('COMBUSTIBLE', $etiquetas, 'El total no se duplica como medida suelta.');
        self::assertNotContains('BATERIA', $etiquetas, 'Lo que sí sabemos interpretar no se muestra como desconocido.');
        self::assertNotContains('ESTADO MOTOR', $etiquetas);
    }

    public function testUnSensorDigitalSinTraducirSeConservaSinInterpretar(): void
    {
        $valores = $this->valores();
        $valores['sensors']['3']['value'] = 1;

        $instantanea = (new WialonSnapshotMapper())->map($valores, $this->definiciones());

        self::assertTrue($instantanea->ralentiActivo(), 'El ralentí sí se reconoce por etiqueta.');
    }

    public function testUnaPosicionEnElOceanoNoSeTraduce(): void
    {
        $valores = $this->valores();
        $valores['pos']['y'] = 0.0;
        $valores['pos']['x'] = 0.0;

        self::assertNull(
            (new WialonSnapshotMapper())->map($valores, $this->definiciones())->posicion(),
            '(0,0) es la ausencia de fix, no una ubicación.',
        );
    }

    public function testUnaUnidadSinSensoresIgualTraducePosicionYOdometro(): void
    {
        $valores = $this->valores();
        unset($valores['sensors']);

        $instantanea = (new WialonSnapshotMapper())->map($valores, []);

        self::assertSame(487500, $instantanea->kilometraje());
        self::assertNull($instantanea->voltaje());
        self::assertNull($instantanea->combustibleLitros());
        self::assertSame([], $instantanea->sensoresAdicionales());
        self::assertNotNull($instantanea->posicion());
    }

    public function testLasHorasSeGuardanEnDecimasYNoComoFloat(): void
    {
        $valores = $this->valores();
        $valores['engine_hours']['value'] = 4062.55;

        self::assertSame(
            40626,
            (new WialonSnapshotMapper())->map($valores, $this->definiciones())->horasDecimales(),
        );
    }
}