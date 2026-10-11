<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\LecturaImposible;
use App\Domain\Telematic\MedidaAdicional;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La muestra no reimplementa las reglas de `InstantaneaEquipo`: las contiene.
 *
 * Esa es la decisión que sostiene a esta clase. `InstantaneaEquipo` ya sabe qué
 * valores son imposibles y ya sabe moverlos a `anomalias` sin ensuciar el
 * resto. Si la muestra volviera a comprobar los signos, habría dos lugares
 * donde una entrada analógica desconectada se convierte en alerta, y el segundo
 * casi seguro se olvida de hacerlo.
 *
 * Lo que la muestra agrega es identidad y procedencia: de qué empresa, de qué
 * integración, de qué unidad externa y cuándo la consultamos nosotros. El resto
 * del vocabulario ya existía.
 *
 * @internal
 */
final class MuestraEquipoTest extends TestCase
{
    private const OBSERVADA = '2026-10-10 06:15:00';

    public function testUnaMuestraValidaConservaTodasLasMetricas(): void
    {
        $muestra = $this->muestra();

        self::assertSame(7, $muestra->empresaId());
        self::assertSame(10, $muestra->integracionId());
        self::assertSame(42, $muestra->equipoId());
        self::assertSame('WIALON-101', $muestra->unidadExterna());
        self::assertSame('WIALON', $muestra->proveedor());

        self::assertSame(487500, $muestra->kilometraje());
        self::assertSame(40625, $muestra->horasDecimales());
        self::assertFalse($muestra->motorEncendido());
        self::assertFalse($muestra->ralentiActivo());
        self::assertEqualsWithDelta(24.51, (float) $muestra->voltaje(), 0.0005);
        self::assertEqualsWithDelta(660.48, (float) $muestra->combustibleLitros(), 0.0005);

        self::assertSame([], $muestra->anomalias());
        self::assertFalse($muestra->tieneAnomalias());
    }

    public function testUnKilometrajeNegativoNoContaminaLaMuestraQuedaComoAnomalia(): void
    {
        $muestra = $this->muestra(kilometraje: -348201);

        self::assertNull($muestra->kilometraje(), 'Un odómetro negativo no es un dato.');

        $anomalias = $muestra->anomalias();
        self::assertCount(1, $anomalias);
        self::assertSame('KILOMETRAJE', $anomalias[0]->concepto());
        self::assertSame(-348201.0, $anomalias[0]->valorLeido());
    }

    public function testUnHorometroNegativoQuedaComoAnomalia(): void
    {
        $muestra = $this->muestra(horasDecimales: -12);

        self::assertNull($muestra->horasDecimales());
        self::assertSame('HORAS', $muestra->anomalias()[0]->concepto());
    }

    public function testCeroVoltajeSignificaSensorApagadoNoBateriaDescargada(): void
    {
        $muestra = $this->muestra(voltaje: 0.0);

        self::assertNull($muestra->voltaje());
        self::assertSame('VOLTAJE', $muestra->anomalias()[0]->concepto());
    }

    public function testUnCombustibleNegativoQuedaComoAnomalia(): void
    {
        // Caso real de la flota: entrada analógica desconectada.
        $muestra = $this->muestra(combustibleLitros: -348.201);

        self::assertNull($muestra->combustibleLitros());
        self::assertSame('COMBUSTIBLE', $muestra->anomalias()[0]->concepto());
    }

    public function testVariasLecturasImposiblesSeConservanTodas(): void
    {
        $muestra = $this->muestra(
            kilometraje: -1,
            voltaje: 0.0,
            combustibleLitros: -348.201,
        );

        $conceptos = array_map(
            static fn (LecturaImposible $anomalia): string => $anomalia->concepto(),
            $muestra->anomalias(),
        );

        self::assertSame(['KILOMETRAJE', 'VOLTAJE', 'COMBUSTIBLE'], $conceptos);
        self::assertNull($muestra->kilometraje());
        self::assertNull($muestra->voltaje());
        self::assertNull($muestra->combustibleLitros());
    }

    public function testLaMuestraNoRepiteLasAnomaliasQueLaInstantaneaYaDetecto(): void
    {
        // La instantánea ya sabe cuáles valores son imposibles y ya los movió.
        // Repetir el chequeo en la muestra duplicaría la entrada de la alerta y
        // su firma lógica, con lo cual el mismo fallo avisaría dos veces.
        $instantanea = $this->instantanea(combustibleLitros: -348.201);
        $muestra = new MuestraEquipo(7, 10, 42, 'WIALON-101', 'WIALON', $instantanea, $this->registrada());

        self::assertCount(1, $muestra->anomalias());
        self::assertCount(1, $instantanea->anomalias());
        self::assertSame(
            $muestra->anomalias()[0]->firma(),
            $instantanea->anomalias()[0]->firma(),
            'La misma falla no debe generar dos firmas distintas.',
        );
    }

    public function testLosSensoresAdicionalesSeConservanConSuEtiquetaOriginal(): void
    {
        $instantanea = new InstantaneaEquipo(
            new DateTimeImmutable(self::OBSERVADA),
            $this->posicion(),
            487500,
            40625,
            false,
            false,
            24.51,
            660.48,
            [new MedidaAdicional('Presión de(neumático)', 98.0, 'kPa', 'custom')],
        );

        $muestra = new MuestraEquipo(7, 10, 42, 'WIALON-101', 'WIALON', $instantanea, $this->registrada());

        $medidas = $muestra->sensoresAdicionales();

        self::assertCount(1, $medidas);
        self::assertSame('Presión de(neumático)', $medidas[0]->etiqueta());
        self::assertSame('kPa', $medidas[0]->unidad());
        self::assertEqualsWithDelta(98.0, (float) $medidas[0]->valor(), 0.0005);
    }

    public function testLaDemoraDiceCuantoTeniaLaLecturaCuandoLaGuardamos(): void
    {
        // Caso normal: el proveedor llevaba un rato sin reportar y por eso la
        // lectura ya tenía esa antigüedad cuando la consultamos.
        $normal = new MuestraEquipo(
            7,
            10,
            42,
            'WIALON-101',
            'WIALON',
            new InstantaneaEquipo(
                new DateTimeImmutable('2026-10-10 06:15:00'),
                $this->posicion('2026-10-10 06:15:00'),
                487500,
                40625,
                false,
                false,
                24.51,
                660.48,
            ),
            new DateTimeImmutable('2026-10-10 06:19:30'),
        );

        self::assertSame(4, $normal->demoraMinutos());
        self::assertFalse($normal->relojProveedorAdelantado(), 'Un proveedor con retraso es lo esperable.');

        // Anómalo: el proveedor reportó una hora que todavía no ocurrió. El signo
        // negativo es la señal, y se conserva en vez de recortarse a cero.
        $adelantado = new MuestraEquipo(
            7,
            10,
            42,
            'WIALON-101',
            'WIALON',
            new InstantaneaEquipo(
                new DateTimeImmutable('2026-10-10 06:15:00'),
                $this->posicion('2026-10-10 06:15:00'),
                487500,
                40625,
                false,
                false,
                24.51,
                660.48,
            ),
            new DateTimeImmutable('2026-10-10 06:10:00'),
        );

        self::assertSame(-5, $adelantado->demoraMinutos());
        self::assertTrue($adelantado->relojProveedorAdelantado());
    }

    public function testLaAntiguedadSeMideDesdeLaHoraDelProveedor(): void
    {
        $muestra = $this->muestra();

        self::assertSame(
            45,
            $muestra->antiguedadMinutos(new DateTimeImmutable('2026-10-10 07:00:00')),
            'La antigüedad es de la captura, no del momento en que la consultamos.',
        );
    }

    /**
     * Los closures son estáticos porque un data provider corre antes de que
     * exista la instancia de la prueba, así que no pueden usar `$this`.
     *
     * @return iterable<string, array{callable(): MuestraEquipo}>
     */
    public static function identidadInvalida(): iterable
    {
        $instantanea = static fn (): InstantaneaEquipo => new InstantaneaEquipo(
            new DateTimeImmutable(self::OBSERVADA),
            new Posicion(-33.0944, -68.88, 0.0, 216, 955.4, 25, new DateTimeImmutable(self::OBSERVADA)),
            487500,
            40625,
            false,
            false,
            24.51,
            660.48,
        );
        $registrada = new DateTimeImmutable('2026-10-10 06:15:04');

        yield 'empresa cero' => [static fn (): MuestraEquipo => new MuestraEquipo(0, 10, 42, 'WIALON-101', 'WIALON', $instantanea(), $registrada)];
        yield 'integración cero' => [static fn (): MuestraEquipo => new MuestraEquipo(7, 0, 42, 'WIALON-101', 'WIALON', $instantanea(), $registrada)];
        yield 'equipo cero' => [static fn (): MuestraEquipo => new MuestraEquipo(7, 10, 0, 'WIALON-101', 'WIALON', $instantanea(), $registrada)];
        yield 'unidad externa vacía' => [static fn (): MuestraEquipo => new MuestraEquipo(7, 10, 42, '   ', 'WIALON', $instantanea(), $registrada)];
        yield 'proveedor vacío' => [static fn (): MuestraEquipo => new MuestraEquipo(7, 10, 42, 'WIALON-101', '', $instantanea(), $registrada)];
    }

    /**
     * @param callable(): MuestraEquipo $construir
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('identidadInvalida')]
    public function testUnaMuestraSinIdentidadNoSePuedeConstruir(callable $construir): void
    {
        // Una muestra sin empresa no se puede leer después: el scoping por
        // empresa es la invariante de seguridad de todo el sistema.
        $this->expectException(InvalidArgumentException::class);

        $construir();
    }

    private function muestra(
        ?int $kilometraje = 487500,
        ?int $horasDecimales = 40625,
        ?float $voltaje = 24.51,
        ?float $combustibleLitros = 660.48,
    ): MuestraEquipo {
        return new MuestraEquipo(
            7,
            10,
            42,
            'WIALON-101',
            'WIALON',
            $this->instantanea($kilometraje, $horasDecimales, $voltaje, $combustibleLitros),
            $this->registrada(),
        );
    }

    private function instantanea(
        ?int $kilometraje = 487500,
        ?int $horasDecimales = 40625,
        ?float $voltaje = 24.51,
        ?float $combustibleLitros = 660.48,
    ): InstantaneaEquipo {
        return new InstantaneaEquipo(
            new DateTimeImmutable(self::OBSERVADA),
            $this->posicion(),
            $kilometraje,
            $horasDecimales,
            false,
            false,
            $voltaje,
            $combustibleLitros,
        );
    }

    private function posicion(string $observada = self::OBSERVADA): Posicion
    {
        return new Posicion(-33.0944, -68.88, 0.0, 216, 955.4, 25, new DateTimeImmutable($observada));
    }

    private function registrada(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-10 06:15:04');
    }
}