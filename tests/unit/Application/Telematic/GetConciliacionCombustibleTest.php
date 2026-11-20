<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\GetConciliacionCombustible;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Application\Telematic\Port\ViajesDeclaradosReader;
use App\Domain\Telematic\Conciliacion\ViajeDeclarado;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Telematic\SerieTanque;

final class ViajesConciliacionEnMemoria implements ViajesDeclaradosReader
{
    /** @var list<array{empresa:int, sucursales:?array}> */
    public array $consultas = [];

    /** @param list<ViajeDeclarado> $viajes */
    public function __construct(private array $viajes = [])
    {
    }

    public function viajesEntre(int $empresaId, ?array $sucursalIds, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->consultas[] = ['empresa' => $empresaId, 'sucursales' => $sucursalIds];

        return array_values(array_filter(
            $this->viajes,
            static fn (ViajeDeclarado $v): bool => $sucursalIds === null || in_array($v->sucursalId, $sucursalIds, true),
        ));
    }
}

final class SerieConciliacionFija implements SerieMuestrasReader
{
    /** @var list<array{empresa:int, equipo:int}> */
    public array $consultas = [];

    public function __construct(private array $muestras = [])
    {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->consultas[] = ['empresa' => $empresaId, 'equipo' => $equipoId];

        return $this->muestras;
    }
}

final class RelojConciliacionFijo implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-30 12:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}

final class GetConciliacionCombustibleTest extends TestCase
{
    private function actor(array $permisos = [GetConciliacionCombustible::PERMISO], bool $todas = true, array $sucursales = []): ActorContext
    {
        return new ActorContext(7, 4, false, $todas, ['Gerencial'], $permisos, $sucursales);
    }

    private function viaje(int $id, int $sucursal, ?int $equipo, float $litros): ViajeDeclarado
    {
        $zona = new DateTimeZone('America/Argentina/Buenos_Aires');

        return new ViajeDeclarado(
            $id,
            $id,
            ViajeDeclarado::FINALIZADO,
            $sucursal,
            $equipo,
            'AB499OK',
            new DateTimeImmutable('2026-10-26 00:00', $zona),
            new DateTimeImmutable('2026-10-26 00:00', $zona),
            null,
            null,
            $litros,
            1,
        );
    }

    /** Sale con 300 L, carga 400 L a las 10:00 y queda parado. */
    private function serie(): array
    {
        return SerieTanque::cada(30, '2026-10-25 18:00', array_merge(array_fill(0, 32, 300), array_fill(0, 60, 700)));
    }

    public function testSinPermisoNoSeConsulta(): void
    {
        $viajes = new ViajesConciliacionEnMemoria();
        $caso = new GetConciliacionCombustible($viajes, new SerieConciliacionFija(), new RelojConciliacionFijo());

        $this->expectException(DomainException::class);
        try {
            $caso->execute($this->actor(['equipos.ver']));
        } finally {
            self::assertSame([], $viajes->consultas);
        }
    }

    public function testElSuperadministradorGlobalNoConsultaUnaFlota(): void
    {
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria(), new SerieConciliacionFija(), new RelojConciliacionFijo());

        $this->expectException(DomainException::class);
        $caso->execute(new ActorContext(1, null, true, true, [], [GetConciliacionCombustible::PERMISO], []));
    }

    public function testUnPeriodoFueraDeRangoSeRechaza(): void
    {
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria(), new SerieConciliacionFija(), new RelojConciliacionFijo());

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(), 120);
    }

    public function testUnUsuarioConSucursalesSoloVeLasSuyas(): void
    {
        $viajes = new ViajesConciliacionEnMemoria([$this->viaje(1, 4, 42, 400.0), $this->viaje(2, 9, 43, 400.0)]);
        $caso = new GetConciliacionCombustible($viajes, new SerieConciliacionFija($this->serie()), new RelojConciliacionFijo());

        $resultado = $caso->execute($this->actor(todas: false, sucursales: [4]));

        self::assertSame([4], $viajes->consultas[0]['sucursales']);
        self::assertSame([1], array_column($resultado['viajes'], 'viajeId'));
    }

    public function testNoSePuedeFiltrarPorUnaSucursalAjena(): void
    {
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria(), new SerieConciliacionFija(), new RelojConciliacionFijo());

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(todas: false, sucursales: [4]), 30, 9);
    }

    public function testUnUsuarioSinSucursalesNoConsultaNada(): void
    {
        $viajes = new ViajesConciliacionEnMemoria([$this->viaje(1, 4, 42, 400.0)]);
        $caso = new GetConciliacionCombustible($viajes, new SerieConciliacionFija(), new RelojConciliacionFijo());

        $resultado = $caso->execute($this->actor(todas: false, sucursales: []));

        self::assertSame([], $viajes->consultas);
        self::assertSame(0, $resultado['totales']['viajes']);
    }

    public function testLaSerieSeLeeConLaEmpresaDelActorYElTractorDelViaje(): void
    {
        $serie = new SerieConciliacionFija($this->serie());
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria([$this->viaje(1, 4, 42, 400.0)]), $serie, new RelojConciliacionFijo());

        $caso->execute($this->actor());

        self::assertSame([['empresa' => 4, 'equipo' => 42]], $serie->consultas);
    }

    public function testLosTotalesSoloSumanViajesConVeredicto(): void
    {
        // El viaje 1 se concilia; el 2 no tiene tractor y no debe sumar sus 900 L declarados.
        $viajes = new ViajesConciliacionEnMemoria([$this->viaje(1, 4, 42, 420.0), $this->viaje(2, 4, null, 900.0)]);
        $caso = new GetConciliacionCombustible($viajes, new SerieConciliacionFija($this->serie()), new RelojConciliacionFijo());

        $resultado = $caso->execute($this->actor());

        self::assertSame(2, $resultado['totales']['viajes']);
        self::assertSame(1, $resultado['totales']['conVeredicto']);
        self::assertSame(1, $resultado['totales']['porEstado']['CONCILIADO']);
        self::assertSame(1, $resultado['totales']['porEstado']['SIN_DATOS_SUFICIENTES']);
        self::assertSame(420.0, $resultado['totales']['litrosDeclarados']);
        self::assertEqualsWithDelta(400.0, $resultado['totales']['litrosDetectados'], 1.0);
        self::assertSame('El viaje no tiene tractor asignado.', $resultado['viajes'][1]['motivo']);
    }

    public function testSinViajesConVeredictoLosTotalesNoInventanCifras(): void
    {
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria([$this->viaje(2, 4, null, 900.0)]), new SerieConciliacionFija(), new RelojConciliacionFijo());

        $resultado = $caso->execute($this->actor());

        self::assertNull($resultado['totales']['litrosDeclarados']);
        self::assertNull($resultado['totales']['diferenciaPorcentaje']);
    }

    public function testLasHorasSeInformanEnLaZonaDelSistemaAunqueLaSerieVengaEnUtc(): void
    {
        // Los buckets llegan en UTC: 13:00 UTC son las 10:00 de Buenos Aires.
        $utc = [];
        foreach ($this->serie() as $muestra) {
            $en = $muestra->observadaEn()->setTimezone(new DateTimeZone('UTC'));
            $utc[] = new \App\Domain\Telematic\MuestraEquipo(4, 1, 42, 'WIALON-42', 'wialon', new \App\Domain\Telematic\InstantaneaEquipo($en, null, 100000, null, false, false, 24.8, $muestra->combustibleLitros()), $en);
        }
        $caso = new GetConciliacionCombustible(new ViajesConciliacionEnMemoria([$this->viaje(1, 4, 42, 400.0)]), new SerieConciliacionFija($utc), new RelojConciliacionFijo());

        $resultado = $caso->execute($this->actor());

        self::assertSame('2026-10-26 09:30', $resultado['viajes'][0]['medido']['detalleCargas'][0]['inicio']);
        self::assertSame('2026-10-26 00:00', $resultado['viajes'][0]['desde']);
    }
}
