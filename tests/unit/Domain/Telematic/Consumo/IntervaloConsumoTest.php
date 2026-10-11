<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic\Consumo;

use App\Domain\Telematic\Consumo\ClasificacionIntervalo;
use App\Domain\Telematic\Consumo\CalidadIntervalo;
use App\Domain\Telematic\Consumo\IntervaloConsumo;
use App\Domain\Telematic\Consumo\PoliticaAtribucion;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La atribución de litros sobre el intervalo entre dos capturas.
 *
 * La regla de oro de este archivo: **un intervalo no puede decir "no hubo
 * carga"**. Del proveedor llega un único número de combustible agregado, sin
 * separación por tanques, así que una transferencia entre tanques es
 * indistinguible de un consumo chico. Por eso un ascenso que no llega al umbral
 * de carga se marca como "sin variación" **y** como posible transferencia: es
 * una hipótesis abierta, no un hecho.
 *
 * @internal
 */
final class IntervaloConsumoTest extends TestCase
{
    private const POLITICA = [
        'deltaMinimoLitros' => 2.0,
        'umbralCargaLitros' => 20.0,
        'huecoMaximoMinutos' => 30,
    ];

    public function testUnDescensoDeCombustibleEsConsumo(): void
    {
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: 500.0),
            $this->muestra('2026-10-10 06:30:00', litros: 486.0),
        );

        self::assertSame(ClasificacionIntervalo::Consumo, $intervalo->clasificacion());
        self::assertEqualsWithDelta(14.0, (float) $intervalo->litros(), 0.0005);
        self::assertSame(CalidadIntervalo::Completo, $intervalo->calidad());
        self::assertFalse($intervalo->puedeHaberTransferencia());
    }

    public function testKilometrajeYHorasSalenDeLasDiferenciasEntreCapturas(): void
    {
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', km: 487500, horas: 40625),
            $this->muestra('2026-10-10 06:30:00', km: 487612, horas: 40640),
        );

        self::assertSame(112, $intervalo->kilometraje());
        // El dominio guarda décimas de hora: 40640 - 40625 = 15 décimas = 1,5 h.
        self::assertEqualsWithDelta(1.5, (float) $intervalo->horasMotor(), 0.0005);
    }

    public function testUnAscensoPorEncimaDelUmbralEsCargaYNoConsumo(): void
    {
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: 300.0),
            $this->muestra('2026-10-10 06:30:00', litros: 620.0),
        );

        self::assertSame(ClasificacionIntervalo::Carga, $intervalo->clasificacion());
        self::assertSame(0.0, $intervalo->litros(), 'Un llenado no es consumo negativo.');
        self::assertSame(320.0, $intervalo->litrosCargados());
        self::assertFalse($intervalo->puedeHaberTransferencia(), 'Acá sí sabemos que fue carga.');
    }

    public function testUnDeltaChicoNoEsCeroEsSinVariacion(): void
    {
        // El sensor de un tanque fluctúa. Un delta de 1,5 l entre dos muestras no
        // es "no consumió": es ruido. Pero informarlo como 0 haría que el
        // consumo por viaje mostrara una celda vacía que parece medida.
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: 500.0),
            $this->muestra('2026-10-10 06:30:00', litros: 498.5),
        );

        self::assertSame(ClasificacionIntervalo::SinVariacion, $intervalo->clasificacion());
        self::assertSame(0.0, $intervalo->litros());
        // Con signo: -1,5 dice que el tanque bajó un poco, que es más
        // información que un 1,5 sin dirección.
        self::assertEqualsWithDelta(-1.5, $intervalo->deltaSinClasificar(), 0.0005);
    }

    public function testUnAscensoChimoNoSeDescartaComoCargaPuedeSerTransferencia(): void
    {
        // Subió 6 litros, menos que el umbral de carga. Con un solo número
        // agregado no hay forma de distinguir una transferencia entre tanques
        // de una carga chica: el sistema no puede afirmar que no hubo carga.
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: 500.0),
            $this->muestra('2026-10-10 06:30:00', litros: 506.0),
        );

        self::assertSame(ClasificacionIntervalo::SinVariacion, $intervalo->clasificacion());
        self::assertTrue(
            $intervalo->puedeHaberTransferencia(),
            'Con combustible agregado no se puede descartar una transferencia.',
        );
        self::assertFalse($intervalo->huboCarga(), 'Y tampoco se puede afirmar que la hubo.');
    }

    public function testSinCombustibleEnUnaDeLasMuestrasNoSeCalculaNada(): void
    {
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: null),
            $this->muestra('2026-10-10 06:30:00', litros: 480.0),
        );

        self::assertSame(ClasificacionIntervalo::SinCombustible, $intervalo->clasificacion());
        self::assertNull($intervalo->litros(), 'Ausencia de sensor no es cero.');
        self::assertNull($intervalo->litrosCargados());
        // El resto del intervalo sigue siendo calculable.
        self::assertSame(CalidadIntervalo::Completo, $intervalo->calidad());
    }

    public function testUnHuecoMayorAlMaximoMarcaElIntervaloComoHueco(): void
    {
        $corto = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00'),
            $this->muestra('2026-10-10 06:30:00'),
        );

        self::assertSame(CalidadIntervalo::Completo, $corto->calidad());
        self::assertSame(15, $corto->duracionMinutos());

        // 3 días de hueco: los litros y los kilómetros existen, pero no se
        // pueden atribuir al tiempo. El motor puede haber cargado, haber parado
        // en un taller o haber estado quieto.
        $hueco = $this->intervalo(
            $this->muestra('2026-10-07 18:28:49', litros: 500.0, km: 487000),
            $this->muestra('2026-10-10 18:28:49', litros: 420.0, km: 487800),
        );

        self::assertSame(CalidadIntervalo::Hueco, $hueco->calidad());
        self::assertSame(4320, $hueco->duracionMinutos());
        self::assertFalse($hueco->consumoUtilizable(), 'Un hueco no habilita el consumo por tiempo.');
        self::assertSame(ClasificacionIntervalo::Consumo, $hueco->clasificacion(), 'Los litros existen igual; lo que falta es el tiempo.');
    }

    public function testUnIntervaloDeQuinceMinutosConDatosEsUtilizable(): void
    {
        $intervalo = $this->intervalo(
            $this->muestra('2026-10-10 06:15:00', litros: 500.0, km: 487500, horas: 40625),
            $this->muestra('2026-10-10 06:30:00', litros: 486.0, km: 487612, horas: 40640),
        );

        self::assertTrue($intervalo->consumoUtilizable());
    }

    public function testDosCapturasDeEquiposDistintosNoFormanUnIntervalo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntervaloConsumo::entre(
            $this->muestra('2026-10-10 06:15:00', equipoId: 42),
            $this->muestra('2026-10-10 06:30:00', equipoId: 43),
            new PoliticaAtribucion(...self::POLITICA),
        );
    }

    public function testDosCapturasDeIntegracionesDistintasNoFormanUnIntervalo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntervaloConsumo::entre(
            $this->muestra('2026-10-10 06:15:00', integracionId: 1),
            $this->muestra('2026-10-10 06:30:00', integracionId: 2),
            new PoliticaAtribucion(...self::POLITICA),
        );
    }

    public function testLaSegundaCapturaNoPuedeSerAnteriorALaPrimera(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntervaloConsumo::entre(
            $this->muestra('2026-10-10 06:30:00'),
            $this->muestra('2026-10-10 06:15:00'),
            new PoliticaAtribucion(...self::POLITICA),
        );
    }

    public function testUnaPoliticaSinUmbralDeCargaNoPuedeSepararCargaDeConsumo(): void
    {
        // Sin umbral no hay forma de decidir, y decidir por omisión sería
        // asumir que todo descenso es consumo.
        $this->expectException(InvalidArgumentException::class);

        new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 0.0, huecoMaximoMinutos: 30);
    }

    private function intervalo(MuestraEquipo $desde, MuestraEquipo $hasta): IntervaloConsumo
    {
        return IntervaloConsumo::entre($desde, $hasta, new PoliticaAtribucion(...self::POLITICA));
    }

    private function muestra(
        string $observada,
        int $equipoId = 42,
        int $integracionId = 1,
        ?float $litros = 500.0,
        ?int $km = 487500,
        ?int $horas = 40625,
    ): MuestraEquipo {
        $instante = new DateTimeImmutable($observada);

        return new MuestraEquipo(
            7,
            $integracionId,
            $equipoId,
            'WIALON-' . $equipoId,
            'wialon',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-32.8051950, -70.0725300, 0.0, 320, 3217.30, 18, $instante),
                $km,
                $horas,
                false,
                false,
                28.52,
                $litros,
            ),
            $instante,
        );
    }
}