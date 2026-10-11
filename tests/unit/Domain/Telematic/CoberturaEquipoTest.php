<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoberturaEquipoTest extends TestCase
{
    private const AHORA = '2026-10-09 12:00:00';

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private function fuente(
        string $integrationId,
        string $provider,
        string $unidad,
        ?string $senal,
        string $rol = 'SECUNDARIA',
    ): FuenteSenal {
        $instantanea = null;

        if ($senal !== null) {
            $momento = new DateTimeImmutable($senal);
            $instantanea = new InstantaneaEquipo(
                $momento,
                new Posicion(-33.09, -68.88, 0.0, null, null, null, $momento),
                null,
                null,
                null,
                null,
                null,
                null,
            );
        }

        return new FuenteSenal(
            $integrationId,
            $provider,
            $provider . ' ' . $integrationId,
            $unidad,
            $rol,
            $instantanea === null ? null : new EstadoSenal($unidad, $instantanea),
        );
    }

    private function cobertura(array $fuentes): CoberturaEquipo
    {
        return new CoberturaEquipo(4, 4, 61, 'ITV9J84', $fuentes);
    }

    public function testUnEquipoSinFuentesNoEstaSinMonitorear(): void
    {
        $cobertura = $this->cobertura([]);

        self::assertFalse($cobertura->estaMonitoreado());
        self::assertFalse(
            $cobertura->estaSinMonitorear($this->ahora(), 24),
            'Un equipo no enlazado es un problema de configuración, no un hueco de monitoreo.',
        );
        self::assertSame([], $cobertura->fuentesCaidas($this->ahora(), 24));
    }

    public function testUnaSolaFuenteRecienteMantieneElEquipoMonitoreado(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-09 11:00:00'),
        ]);

        self::assertTrue($cobertura->estaMonitoreado());
        self::assertTrue($cobertura->algunaReporta($this->ahora(), 24));
        self::assertFalse($cobertura->estaSinMonitorear($this->ahora(), 24));
    }

    public function testElEquipoQuedaSinMonitorearSoloSiNingunaFuenteReporta(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-01 00:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-01 00:00:00'),
        ]);

        self::assertTrue($cobertura->estaSinMonitorear($this->ahora(), 24));
    }

    public function testUnaSegundaFuenteVivaEvitaLaAlertaDeFlota(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-01 00:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-09 11:30:00'),
        ]);

        self::assertFalse(
            $cobertura->estaSinMonitorear($this->ahora(), 24),
            'Con Gestya reportando, el camión no está sin monitorear aunque Wialon esté mudo.',
        );
        self::assertTrue($cobertura->algunaReporta($this->ahora(), 24));
    }

    public function testUnaFuenteCaidaSeReportaComoProblemaDeIntegracion(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-01 00:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-09 11:30:00'),
        ]);

        $caidas = $cobertura->fuentesCaidas($this->ahora(), 24);

        self::assertCount(1, $caidas);
        self::assertSame('wialon', $caidas[0]->provider());
        self::assertSame('28396292', $caidas[0]->unidadExterna());
    }

    public function testLaUltimaSenalTomaLaMasRecienteEntreTodasLasFuentes(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-05 08:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-09 11:30:00'),
            $this->fuente('3', 'otro', 'X-1', '2026-10-07 09:00:00'),
        ]);

        self::assertSame(
            '2026-10-09 11:30:00',
            $cobertura->ultimaSenalEn()?->format('Y-m-d H:i:s'),
        );
        self::assertSame('20261009113000', $cobertura->ciclo());
    }

    public function testElCicloNoCambiaMientrasLaSenalMasRecienteNoCambia(): void
    {
        $uno = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-05 08:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-09 11:30:00'),
        ]);
        $otro = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2026-10-02 08:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2026-10-09 11:30:00'),
        ]);

        self::assertSame(
            $uno->ciclo(),
            $otro->ciclo(),
            'Lo que importa para la clave lógica es la señal más reciente, no la de cada fuente.',
        );
    }

    public function testUnaFuenteVinculadaQueElProveedorNoDevolvioNoSeMarcaEstancada(): void
    {
        $fuente = new FuenteSenal('1', 'wialon', 'Wialon TSA', '999999', 'SECUNDARIA', null);

        self::assertFalse(
            $fuente->estaEstancada($this->ahora(), 1),
            'Una señal ausente se distingue de una señal vieja: la primera es un vínculo mal configurado.',
        );
        self::assertNull($fuente->reportaEn());
    }

    public function testUmbralCeroDesactivaTodoElCriterio(): void
    {
        $cobertura = $this->cobertura([
            $this->fuente('1', 'wialon', '28396292', '2020-01-01 00:00:00'),
            $this->fuente('2', 'gestya', 'G-777', '2020-01-01 00:00:00'),
        ]);

        self::assertFalse($cobertura->estaSinMonitorear($this->ahora(), 0));
        self::assertFalse($cobertura->algunaReporta($this->ahora(), 0));
        self::assertSame([], $cobertura->fuentesCaidas($this->ahora(), 0));
    }

    public function testLaCoberturaExigeAlcanceValido(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoberturaEquipo(0, null, 61, 'ITV9J84', []);
    }
}