<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\GetUsoFlota;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Telematic\SerieUso;

final class FlotaUsoEnMemoria implements FleetTelemetryBoardReader
{
    /** @var list<array{empresa:int, sucursales:?array}> */
    public array $consultas = [];

    public function __construct(private array $units = [])
    {
    }

    public function read(int $companyId, ?array $branchIds): array
    {
        $this->consultas[] = ['empresa' => $companyId, 'sucursales' => $branchIds];

        return [
            'units' => array_values(array_filter(
                $this->units,
                static fn (array $u): bool => $branchIds === null || in_array($u['branchId'], $branchIds, true),
            )),
            'branches' => [],
        ];
    }
}

final class SeriesUsoPorEquipo implements SerieMuestrasReader
{
    /** @var list<array{empresa:int, equipo:int}> */
    public array $consultas = [];

    /** @param array<int, list<\App\Domain\Telematic\MuestraEquipo>> $series */
    public function __construct(private array $series = [])
    {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->consultas[] = ['empresa' => $empresaId, 'equipo' => $equipoId];

        return $this->series[$equipoId] ?? [];
    }
}

final class RelojUsoFijo implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-20 12:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}

final class GetUsoFlotaTest extends TestCase
{
    private function actor(array $permisos = [GetUsoFlota::PERMISO], bool $todas = true, array $sucursales = []): ActorContext
    {
        return new ActorContext(7, 4, false, $todas, ['Gerencial'], $permisos, $sucursales);
    }

    private function unidad(int $equipoId, int $sucursal, int $integracionId = 1): array
    {
        return [
            'equipmentId' => $equipoId,
            'code' => 'T' . $equipoId,
            'plate' => 'PAT' . $equipoId,
            'branchId' => $sucursal,
            'branchName' => 'Sucursal ' . $sucursal,
            'detailUrl' => '/mantenimiento/equipos/' . $equipoId . '?tab=telemetria',
            'sources' => [[
                'integrationId' => $integracionId,
                'integrationName' => 'Obersat',
                'provider' => 'wialon',
                'role' => 'PRINCIPAL',
            ]],
        ];
    }

    /** Una muestra cada hora durante los últimos `$horas`, en ruta a 80 km/h. */
    private function reportando(int $equipoId, int $horas = 48, int $hastaHorasAntes = 0): array
    {
        $pasos = [];
        for ($h = 0; $h < $horas; $h++) {
            $pasos[] = [$h * 60, 80.0, true, false, 1000 + $h * 80, 40000 + $h * 10];
        }

        return SerieUso::pasos(
            (new DateTimeImmutable('2026-11-20 12:00', new DateTimeZone('America/Argentina/Buenos_Aires')))
                ->modify('-' . ($horas + $hastaHorasAntes) . ' hours')->format('Y-m-d H:i'),
            $pasos,
            $equipoId,
        );
    }

    public function testSinPermisoNoSeConsulta(): void
    {
        $flota = new FlotaUsoEnMemoria([$this->unidad(42, 4)]);
        $caso = new GetUsoFlota($flota, new SeriesUsoPorEquipo(), new RelojUsoFijo());

        $this->expectException(DomainException::class);
        try {
            $caso->execute($this->actor(['equipos.ver']));
        } finally {
            self::assertSame([], $flota->consultas);
        }
    }

    public function testElPeriodoNoPuedePasarDeUnMes(): void
    {
        $caso = new GetUsoFlota(new FlotaUsoEnMemoria(), new SeriesUsoPorEquipo(), new RelojUsoFijo());

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(), 45);
    }

    public function testUnUsuarioConSucursalesSoloVeLasSuyasYNoPuedePedirOtra(): void
    {
        $flota = new FlotaUsoEnMemoria([$this->unidad(42, 4), $this->unidad(43, 9)]);
        $caso = new GetUsoFlota($flota, new SeriesUsoPorEquipo(), new RelojUsoFijo());

        $resultado = $caso->execute($this->actor(todas: false, sucursales: [4]));

        self::assertSame([4], $flota->consultas[0]['sucursales']);
        self::assertSame([42], array_column($resultado['unidades'], 'equipoId'));

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(todas: false, sucursales: [4]), 7, 9);
    }

    public function testLaSerieSeLeeConLaEmpresaDelActor(): void
    {
        $series = new SeriesUsoPorEquipo([42 => $this->reportando(42)]);
        $caso = new GetUsoFlota(new FlotaUsoEnMemoria([$this->unidad(42, 4)]), $series, new RelojUsoFijo());

        $caso->execute($this->actor());

        self::assertSame([['empresa' => 4, 'equipo' => 42]], $series->consultas);
    }

    public function testUnCamionQueDejoDeReportarSeMarcaSiSuIntegracionFunciona(): void
    {
        $series = new SeriesUsoPorEquipo([
            42 => $this->reportando(42),
            43 => $this->reportando(43),
            44 => $this->reportando(44, 48, 30), // Último dato hace 30 h.
        ]);
        $caso = new GetUsoFlota(new FlotaUsoEnMemoria([$this->unidad(42, 4), $this->unidad(43, 4), $this->unidad(44, 4)]), $series, new RelojUsoFijo());

        $resultado = $caso->execute($this->actor());

        $estados = array_column($resultado['unidades'], 'estadoSenal', 'equipoId');
        self::assertSame([42 => 'OK', 43 => 'OK', 44 => 'SIN_REPORTAR'], $estados);
        self::assertSame(1, $resultado['totales']['sinReportar']);
        self::assertSame([], $resultado['cortes']);
    }

    public function testSiLaIntegracionEstaCortadaLosCamionesNoSeMarcanComoSinReportar(): void
    {
        $series = new SeriesUsoPorEquipo([
            42 => $this->reportando(42, 48, 30),
            43 => $this->reportando(43, 48, 30),
        ]);
        $caso = new GetUsoFlota(new FlotaUsoEnMemoria([$this->unidad(42, 4), $this->unidad(43, 4)]), $series, new RelojUsoFijo());

        $resultado = $caso->execute($this->actor());

        self::assertSame(['CORTE_INTEGRACION', 'CORTE_INTEGRACION'], array_column($resultado['unidades'], 'estadoSenal'));
        self::assertCount(1, $resultado['cortes']);
        self::assertTrue($resultado['cortes'][0]['sigueAbierto']);
        self::assertSame('Obersat', $resultado['cortes'][0]['integracion']);
        self::assertSame(1, $resultado['totales']['cortesAbiertos']);
    }

    public function testLosTotalesNoSumanLoQueNoSeMidio(): void
    {
        // El 43 no tiene datos: no suma km ni horas, ni como cero.
        $series = new SeriesUsoPorEquipo([42 => $this->reportando(42)]);
        $caso = new GetUsoFlota(new FlotaUsoEnMemoria([$this->unidad(42, 4), $this->unidad(43, 4)]), $series, new RelojUsoFijo());

        $resultado = $caso->execute($this->actor());

        self::assertSame(1, $resultado['totales']['unidadesConKm']);
        self::assertSame(47 * 80, $resultado['totales']['km']);
        self::assertSame(1, $resultado['totales']['sinDatos']);
    }
}
