<?php

declare(strict_types=1);

namespace Tests\unit\Domain\Telematic\Conciliacion;

use App\Domain\Telematic\Conciliacion\ConciliacionCombustibleViaje;
use App\Domain\Telematic\Conciliacion\EstadoConciliacion;
use App\Domain\Telematic\Conciliacion\ViajeDeclarado;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Telematic\SerieTanque;

final class ConciliacionCombustibleViajeTest extends TestCase
{
    private function fecha(string $valor): DateTimeImmutable
    {
        return new DateTimeImmutable($valor, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private function viaje(float $litros, string $estado = ViajeDeclarado::FINALIZADO, ?int $equipoId = 42, ?string $regreso = '2026-10-27 00:00'): ViajeDeclarado
    {
        return new ViajeDeclarado(
            21,
            21,
            $estado,
            4,
            $equipoId,
            'JLH877',
            $this->fecha('2026-10-26 00:00'),
            $regreso === null ? null : $this->fecha($regreso),
            100000,
            100800,
            $litros,
            2,
        );
    }

    /**
     * Un viaje del 26/10 con datos cada 30 min desde la noche anterior hasta el
     * 29/10: sale con 300 L, carga 400 L a media mañana y vuelve con 380 L.
     *
     * @return list<\App\Domain\Telematic\MuestraEquipo>
     */
    private function viajeCompleto(): array
    {
        $litros = [];
        $km = [];
        // 25/10 18:00 → 26/10 10:00: parado con 300 L (32 muestras).
        for ($i = 0; $i < 32; $i++) {
            $litros[] = 300;
            $km[] = 100000;
        }
        // Carga de 400 L.
        for ($i = 0; $i < 4; $i++) {
            $litros[] = 700;
            $km[] = 100000;
        }
        // Ruta: 800 km y 320 L hasta las 24 h siguientes.
        for ($i = 1; $i <= 28; $i++) {
            $litros[] = 700 - $i * (320 / 28);
            $km[] = 100000 + (int) round($i * 800 / 28);
        }
        // Llegó y queda parado hasta el 29/10.
        for ($i = 0; $i < 80; $i++) {
            $litros[] = 380;
            $km[] = 100800;
        }

        return SerieTanque::cada(30, '2026-10-25 18:00', $litros, $km);
    }

    private function evaluar(ViajeDeclarado $viaje, array $muestras): ConciliacionCombustibleViaje
    {
        return ConciliacionCombustibleViaje::evaluar($viaje, $muestras, $this->fecha('2026-11-20 17:00'));
    }

    public function testDeclaradoDentroDeLaToleranciaCoincide(): void
    {
        $resultado = $this->evaluar($this->viaje(420.0), $this->viajeCompleto());

        self::assertSame(EstadoConciliacion::Conciliado, $resultado->estado());
        self::assertEqualsWithDelta(400.0, $resultado->litrosDetectados(), 1.0);
        self::assertEqualsWithDelta(5.0, $resultado->diferenciaPorcentaje(), 0.5);
        self::assertSame(800, $resultado->kmGps());
        self::assertEqualsWithDelta(40.0, $resultado->litrosPor100KmMedido(), 0.5);
        self::assertEqualsWithDelta(52.5, $resultado->litrosPor100KmDeclarado(), 0.1);
    }

    public function testDeclararMasDeLoQueVioElTanqueSeMarca(): void
    {
        $resultado = $this->evaluar($this->viaje(600.0), $this->viajeCompleto());

        self::assertSame(EstadoConciliacion::DeclaradoSupera, $resultado->estado());
        self::assertEqualsWithDelta(200.0, $resultado->diferenciaLitros(), 1.0);
        self::assertEqualsWithDelta(50.0, $resultado->diferenciaPorcentaje(), 0.5);
    }

    public function testDeclararMenosDeLoQueVioElTanqueTambienSeMarca(): void
    {
        $resultado = $this->evaluar($this->viaje(200.0), $this->viajeCompleto());

        self::assertSame(EstadoConciliacion::DeclaradoMenor, $resultado->estado());
    }

    public function testUnHuecoLargoNoTerminaEnUnVeredicto(): void
    {
        // Mismo viaje, pero la telemetría se corta 10 horas en el medio.
        $completo = $this->viajeCompleto();
        $cortado = array_merge(array_slice($completo, 0, 40), array_slice($completo, 60));

        $resultado = $this->evaluar($this->viaje(600.0), $cortado);

        self::assertSame(EstadoConciliacion::SinDatosSuficientes, $resultado->estado());
        self::assertStringContainsString('hueco', (string) $resultado->motivo());
        self::assertNull($resultado->diferenciaLitros());
    }

    public function testUnSensorQueReportaPocoNoTerminaEnUnVeredicto(): void
    {
        $muestras = $this->viajeCompleto();
        $litros = [];
        foreach ($muestras as $i => $muestra) {
            $litros[] = $i % 2 === 0 ? null : $muestra->combustibleLitros();
        }
        $km = array_map(static fn ($m): ?int => $m->kilometraje(), $muestras);

        $resultado = $this->evaluar($this->viaje(600.0), SerieTanque::cada(30, '2026-10-25 18:00', $litros, $km));

        self::assertSame(EstadoConciliacion::SinDatosSuficientes, $resultado->estado());
        self::assertStringContainsString('sensor del tanque', (string) $resultado->motivo());
    }

    public function testSinTelemetriaNoHayVeredicto(): void
    {
        $resultado = $this->evaluar($this->viaje(600.0), []);

        self::assertSame(EstadoConciliacion::SinDatosSuficientes, $resultado->estado());
    }

    public function testUnViajeSinTractorNoSeConcilia(): void
    {
        $resultado = $this->evaluar($this->viaje(600.0, equipoId: null), $this->viajeCompleto());

        self::assertSame(EstadoConciliacion::SinDatosSuficientes, $resultado->estado());
        self::assertStringContainsString('tractor', (string) $resultado->motivo());
    }

    public function testUnViajeEnCursoMuestraLoMedidoPeroNoEmiteVeredicto(): void
    {
        $resultado = ConciliacionCombustibleViaje::evaluar(
            $this->viaje(0.0, ViajeDeclarado::EN_CURSO, regreso: null),
            $this->viajeCompleto(),
            $this->fecha('2026-10-27 12:00'),
        );

        self::assertSame(EstadoConciliacion::EnCurso, $resultado->estado());
        self::assertEqualsWithDelta(400.0, $resultado->litrosDetectados(), 1.0);
        self::assertNull($resultado->diferenciaLitros());
    }

    public function testUnRegresoSinHoraCubreElDiaCompleto(): void
    {
        $viaje = $this->viaje(420.0, regreso: '2026-10-27 00:00');

        self::assertSame('2026-10-27 23:59:59', $viaje->finVentana($this->fecha('2026-11-20 17:00'))->format('Y-m-d H:i:s'));
    }
}
