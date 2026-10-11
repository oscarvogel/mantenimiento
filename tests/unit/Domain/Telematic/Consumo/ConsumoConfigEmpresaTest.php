<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic\Consumo;

use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use App\Domain\Telematic\Consumo\PoliticaConsumo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Umbrales por empresa: qué cubre los defaults y cuándo se puede decir que
 * un número está calibrado.
 *
 * La regla central es que **anular no es poner cero**. `null` es «sin
 * configurar» y el default cubre; un `0` es una decisión con consecuencias:
 * con delta mínimo cero, cada fluctuación del sensor se cuenta como consumo.
 *
 * @internal
 */
final class ConsumoConfigEmpresaTest extends TestCase
{
    public function testSinConfiguracionQuedanLosDefaultsYSinCalibrar(): void
    {
        $politica = PoliticaConsumo::desdeConfig(null);

        self::assertFalse($politica->calibrada);
        self::assertSame(2.0, $politica->atribucion->deltaMinimoLitros);
        self::assertSame(20.0, $politica->atribucion->umbralCargaLitros);
    }

    public function testUnaFilaVaciaTambienEsSinCalibrar(): void
    {
        // La empresa existe en la tabla pero no tocó nada. Sigue siendo
        // defaults, y sigue sin estar calibrado.
        $politica = PoliticaConsumo::desdeConfig(new ConsumoConfigEmpresa(7));

        self::assertFalse($politica->calibrada);
        self::assertSame(20.0, $politica->atribucion->umbralCargaLitros);
    }

    public function testUnValorPropioCambiaSoloEseUmbral(): void
    {
        $politica = PoliticaConsumo::desdeConfig(new ConsumoConfigEmpresa(
            empresaId: 7,
            deltaMinimoLitros: 8.0,
        ));

        self::assertSame(8.0, $politica->atribucion->deltaMinimoLitros);
        self::assertSame(20.0, $politica->atribucion->umbralCargaLitros, 'El resto lo cubre el default.');
        self::assertSame(1, $politica->segmentacion->kmMinimosViaje);
        self::assertTrue($politica->calibrada, 'La empresa decidió algo: el reporte deja de provisorio.');
    }

    public function testSeDistingueQueUmbralesSonDeLaEmpresaYCualesDeFabrica(): void
    {
        $config = new ConsumoConfigEmpresa(empresaId: 7, deltaMinimoLitros: 8.0);
        $politica = PoliticaConsumo::desdeConfig($config);

        $origen = $politica->valoresConOrigen($config);

        self::assertSame('empresa', $origen['deltaMinimoLitros']['origen']);
        self::assertSame('fabrica', $origen['umbralCargaLitros']['origen']);
        self::assertSame(8.0, $origen['deltaMinimoLitros']['valor']);
    }

    public function testTodosLosUmbralesSePuedenConfigurar(): void
    {
        $politica = PoliticaConsumo::desdeConfig(new ConsumoConfigEmpresa(
            empresaId: 7,
            deltaMinimoLitros: 8.0,
            umbralCargaLitros: 60.0,
            huecoMaximoAtribucionMinutos: 45,
            kmMinimosViaje: 3,
            minutosMinimosViaje: 10,
            intervalosParadoParaCerrar: 4,
            coberturaMinima: 0.85,
            huecoMaximoCoberturaMinutos: 20,
            velocidadMinimaPlausibleKmh: 6.0,
            velocidadMaximaPlausibleKmh: 100.0,
        ));

        self::assertSame(8.0, $politica->atribucion->deltaMinimoLitros);
        self::assertSame(60.0, $politica->atribucion->umbralCargaLitros);
        self::assertSame(45, $politica->atribucion->huecoMaximoMinutos);
        self::assertSame(3, $politica->segmentacion->kmMinimosViaje);
        self::assertSame(10, $politica->segmentacion->minutosMinimosViaje);
        self::assertSame(4, $politica->segmentacion->intervalosParadoParaCerrar);
        self::assertSame(0.85, $politica->cobertura->coberturaMinima);
        self::assertSame(20, $politica->cobertura->huecoMaximoMinutos);
        self::assertSame(6.0, $politica->indicadores->velocidadMinimaPlausibleKmh);
        self::assertSame(100.0, $politica->indicadores->velocidadMaximaPlausibleKmh);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function configuracionInvalida(): iterable
    {
        yield 'umbral de carga cero' => [['empresaId' => 7, 'umbralCargaLitros' => 0.0]];
        yield 'umbral menor que el delta' => [['empresaId' => 7, 'deltaMinimoLitros' => 30.0, 'umbralCargaLitros' => 20.0]];
        yield 'cobertura mayor que uno' => [['empresaId' => 7, 'coberturaMinima' => 1.5]];
        yield 'cobertura cero' => [['empresaId' => 7, 'coberturaMinima' => 0.0]];
        yield 'tope por debajo del piso' => [['empresaId' => 7, 'velocidadMinimaPlausibleKmh' => 50.0, 'velocidadMaximaPlausibleKmh' => 20.0]];
        yield 'sin intervalos para cerrar' => [['empresaId' => 7, 'intervalosParadoParaCerrar' => 0]];
        yield 'empresa cero' => [['empresaId' => 0]];
    }

    /**
     * @param  array<string, mixed>                     $argumentos
     * @param callable(array<string, mixed>): mixed $construir
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configuracionInvalida')]
    public function testUnaConfiguracionQueRompeLaInvarianteNoSeGuarda(array $argumentos): void
    {
        // El error tiene que saltar al guardar, no tres semanas después cuando
        // un camión haya cargado a mitad de camino.
        $this->expectException(InvalidArgumentException::class);

        new ConsumoConfigEmpresa(...$argumentos);
    }

    public function testElDeltaMinimoCeroEsValidoPorqueEsUnaDecision(): void
    {
        // Cero no es inválido: es una decisión explícita de no descartar
        // fluctuaciones. El riesgo lo asume quien lo configura.
        $config = new ConsumoConfigEmpresa(empresaId: 7, deltaMinimoLitros: 0.0, umbralCargaLitros: 20.0);

        self::assertTrue($config->configurado());
        self::assertSame(0.0, PoliticaConsumo::desdeConfig($config)->atribucion->deltaMinimoLitros);
    }
}