<?php

declare(strict_types=1);

namespace Tests\unit\Domain\Telematic\Uso;

use App\Domain\Telematic\Uso\DetectorCortesIntegracion;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DetectorCortesIntegracionTest extends TestCase
{
    private function fecha(string $valor): DateTimeImmutable
    {
        return new DateTimeImmutable($valor, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    /**
     * Una unidad que reporta cada hora, salvo entre `$sinDatosDesde` y `$sinDatosHasta`.
     *
     * @return list<int>
     */
    private function cadaHora(string $desde, string $hasta, ?string $sinDatosDesde = null, ?string $sinDatosHasta = null): array
    {
        $marcas = [];
        $hueco = $sinDatosDesde === null ? null : [$this->fecha($sinDatosDesde), $this->fecha((string) $sinDatosHasta)];
        for ($t = $this->fecha($desde); $t <= $this->fecha($hasta); $t = $t->modify('+1 hour')) {
            if ($hueco !== null && $t > $hueco[0] && $t < $hueco[1]) {
                continue;
            }
            $marcas[] = $t->getTimestamp();
        }

        return $marcas;
    }

    public function testMediaFlotaSinDatosALaVezEsUnCorte(): void
    {
        $cortes = (new DetectorCortesIntegracion())->detectar([
            'a' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00', '2026-11-08 10:00', '2026-11-19 08:00'),
            'b' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00', '2026-11-08 10:00', '2026-11-19 08:00'),
            'c' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00'),
        ], $this->fecha('2026-11-20 00:00'));

        self::assertCount(1, $cortes);
        self::assertSame('2026-11-08 10:00', $cortes[0]->desde->format('Y-m-d H:i'));
        self::assertSame('2026-11-19 08:00', $cortes[0]->hasta->format('Y-m-d H:i'));
        self::assertSame(2, $cortes[0]->unidadesAfectadas);
        self::assertSame(3, $cortes[0]->unidadesTotales);
        self::assertFalse($cortes[0]->sigueAbierto);
    }

    public function testUnSoloCamionSinDatosNoEsUnCorteDeLaIntegracion(): void
    {
        $cortes = (new DetectorCortesIntegracion())->detectar([
            'a' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00', '2026-11-08 10:00', '2026-11-19 08:00'),
            'b' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00'),
            'c' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00'),
        ], $this->fecha('2026-11-20 00:00'));

        self::assertSame([], $cortes);
    }

    public function testUnaNocheConLosCamionesParadosNoEsUnCorte(): void
    {
        // Autotrac no reporta con el camión parado: 8 h sin datos a la noche.
        $noche = fn (): array => $this->cadaHora('2026-11-07 00:00', '2026-11-09 00:00', '2026-11-07 22:00', '2026-11-08 06:00');

        $cortes = (new DetectorCortesIntegracion())->detectar(['a' => $noche(), 'b' => $noche(), 'c' => $noche()], $this->fecha('2026-11-09 00:00'));

        self::assertSame([], $cortes);
    }

    public function testHuecosLargosQueCoincidenPocoTiempoNoSonUnCorte(): void
    {
        $cortes = (new DetectorCortesIntegracion())->detectar([
            'a' => $this->cadaHora('2026-11-07 00:00', '2026-11-12 00:00', '2026-11-08 00:00', '2026-11-09 00:00'),
            'b' => $this->cadaHora('2026-11-07 00:00', '2026-11-12 00:00', '2026-11-08 22:00', '2026-11-09 22:00'),
        ], $this->fecha('2026-11-12 00:00'));

        self::assertSame([], $cortes, 'Se solapan solo 2 h.');
    }

    public function testUnCorteQueSigueHastaAhoraQuedaAbierto(): void
    {
        $cortes = (new DetectorCortesIntegracion())->detectar([
            'a' => $this->cadaHora('2026-11-07 00:00', '2026-11-18 00:00'),
            'b' => $this->cadaHora('2026-11-07 00:00', '2026-11-18 00:00'),
        ], $this->fecha('2026-11-20 00:00'));

        self::assertCount(1, $cortes);
        self::assertTrue($cortes[0]->sigueAbierto);
        self::assertEqualsWithDelta(48.0, $cortes[0]->horas(), 0.01);
    }

    public function testUnaUnidadRecienVinculadaNoCuentaComoCortadaAntesDeSuPrimerDato(): void
    {
        $cortes = (new DetectorCortesIntegracion())->detectar([
            'a' => $this->cadaHora('2026-11-07 00:00', '2026-11-20 00:00'),
            'b' => $this->cadaHora('2026-11-15 00:00', '2026-11-20 00:00'),
        ], $this->fecha('2026-11-20 00:00'));

        self::assertSame([], $cortes);
    }
}
