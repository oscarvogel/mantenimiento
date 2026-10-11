<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\CalcularConsumoPorEquipo;
use App\Application\Telematic\GetReporteConsumoFlota;
use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Consumo\ConsumoConfigEmpresa;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Lector de muestras en memoria. */
final class SerieDePrueba implements SerieMuestrasReader
{
    /** @param list<MuestraEquipo> $muestras */
    public function __construct(private array $muestras = [])
    {
    }

    public function serie(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array {
        return array_values(array_filter(
            $this->muestras,
            static fn (MuestraEquipo $m): bool => $m->empresaId() === $empresaId
                && $m->equipoId() === $equipoId
                && $m->observadaEn() >= $desde
                && $m->observadaEn() <= $hasta,
        ));
    }
}

/** Config en memoria. */
final class ConfigEnMemoria implements ConsumoConfigStore
{
    public ?ConsumoConfigEmpresa $guardada = null;

    public function obtener(int $empresaId): ?ConsumoConfigEmpresa
    {
        return $this->guardada;
    }

    public function guardar(ConsumoConfigEmpresa $config): ConsumoConfigEmpresa
    {
        return $this->guardada = $config;
    }
}

/** Reloj fijo. */
final class RelojFijo implements NotificationClock
{
    public function __construct(private string $ahora = '2026-10-10 12:00:00')
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->ahora);
    }
}

/** Flota en memoria: sólo decide qué equipos ve el actor. */
final class FlotaEnMemoria implements \App\Application\Telematic\Port\FleetTelemetryBoardReader
{
    /** @param list<array<string, mixed>> $units */
    public function __construct(private array $units, private ?array $branchIds = null)
    {
    }

    public function read(int $companyId, ?array $branchIds): array
    {
        $this->branchIds = $branchIds;

        return [
            'units' => $this->units,
            'branches' => [['id' => 1, 'name' => 'Casa central']],
        ];
    }

    public function consulto(): ?array
    {
        return $this->branchIds;
    }
}

/**
 * Los datos de la pantalla: equipos visibles, viajes y sus motivos.
 *
 * Lo que se prueba acá es que la pantalla **no pueda mentir**: que un número
 * que no se pudo calcular llegue con su motivo, que los umbrales sin calibrar
 * Viajen marcados, y que un responsable de planta no vea equipos de otra
 * sucursal.
 *
 * @internal
 */
final class GetReporteConsumoFlotaTest extends TestCase
{
    private const UNIDADES = [
        ['equipmentId' => 42, 'plate' => 'AB499OK', 'code' => 'AB499OK', 'branchId' => 1, 'branchName' => 'Casa central'],
        ['equipmentId' => 43, 'plate' => 'GZD587', 'code' => 'GZD587', 'branchId' => 2, 'branchName' => 'Corrientes'],
    ];

    public function testSinPermisoNoDevuelveNada(): void
    {
        $this->expectException(DomainException::class);

        $this->caso(new SerieDePrueba())->execute(
            $this->actor(empresaId: 7, permisos: []),
        );
    }

    public function testUnActorSinEmpresaNoSePuedeConstruir(): void
    {
        // No es una comprobación de esta pantalla: es la invariante del
        // contexto de actor. Un usuario común sin empresa no existe, y por eso
        // acá alcanza con fijar que la construcción lo rechaza.
        $this->expectException(\InvalidArgumentException::class);

        $this->actor(empresaId: null);
    }

    public function testUnPeriodoFueraDeRangoNoSeAcepta(): void
    {
        $this->expectException(DomainException::class);

        $this->caso()->execute($this->actor(), null, 0);
    }

    public function testUnActorSinNingunaSucursalNoVeEquipos(): void
    {
        // Con scoping de sucursal y sin ninguna asignada, no hay nada que ver.
        // No es lo mismo que 've todas': son situaciones distintas.
        $resultado = $this->caso()->execute($this->actor(sucursales: [], todasLasSucursales: false));

        self::assertNull($resultado['reporte']);
        self::assertSame([], $resultado['equipos']);
    }

    public function testLaConsultaSeLimitaALasSucursalesDelActor(): void
    {
        $flota = new FlotaEnMemoria(self::UNIDADES);
        $this->caso(flota: $flota)->execute($this->actor(sucursales: [2], todasLasSucursales: false));

        self::assertSame([2], $flota->consulto(), 'El scoping por sucursal se aplica al leer los equipos.');
    }

    public function testElEquipoSeEligeYSeDevuelvenSusDatos(): void
    {
        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda()))->execute($this->actor(), 42);

        self::assertSame(42, $resultado['equipo']['id']);
        self::assertSame('AB499OK', $resultado['equipo']['plate']);
        self::assertSame(7, $resultado['window']['dias']);
        self::assertSame([33.0, 41.0], [$resultado['bandaFlota']['minima'], $resultado['bandaFlota']['maxima']]);
    }

    public function testSinEquipoSeAgreganTodosLosVisiblesOrdenados(): void
    {
        $resultado = $this->caso()->execute($this->actor());

        self::assertSame(
            [['id' => 42, 'plate' => 'AB499OK', 'branchName' => 'Casa central'],
             ['id' => 43, 'plate' => 'GZD587', 'branchName' => 'Corrientes']],
            $resultado['equipos'],
        );
    }

    public function testLosUmbralesSinCalibrarViajanMarcados(): void
    {
        // El requisito de la pantalla: con defaults de fábrica, el operador
        // tiene que ver que el número es una estimación.
        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda()))->execute($this->actor(), 42);

        self::assertTrue($resultado['reporte']['sinCalibrar']);
    }

    public function testUnaEmpresaQueConfiguroUmbralesDejaDeSalirProvisoria(): void
    {
        $config = new ConfigEnMemoria();
        $config->guardar(new ConsumoConfigEmpresa(7, deltaMinimoLitros: 8.0));

        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda()), config: $config)->execute($this->actor(), 42);

        self::assertFalse($resultado['reporte']['sinCalibrar']);
        self::assertSame(8.0, $resultado['reporte']['umbrales']['deltaMinimoLitros']);
    }

    public function testUnViajeSinConsumoViajaConSuMotivoYNoConCero(): void
    {
        // Sin sensor de combustible, la celda tiene que decir por qué está
        // vacía. Un 0 en un total de litros parece un camión que no gastó nada.
        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda(litros: null)))->execute($this->actor(), 42);

        $viajes = $resultado['reporte']['viajes'];
        self::assertNotSame([], $viajes);
        self::assertNull($viajes[0]['litrosPor100Km']);
        self::assertStringContainsString('combustible', (string) $viajes[0]['motivoSinPor100Km']);
        self::assertSame(1, $resultado['reporte']['totales']['sinConsumo']);
    }

    public function testLaFilaDeTotalNoInventaUnaHora(): void
    {
        // El total es de kilómetros y litros. Las horas van por viaje: un
        // "06:00 - 18:30" sobre una semana no describe nada.
        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda()))->execute($this->actor(), 42);

        self::assertArrayHasKey('kilometraje', $resultado['reporte']['totales']);
        self::assertArrayHasKey('litros', $resultado['reporte']['totales']);
        self::assertArrayNotHasKey('inicio', $resultado['reporte']['totales']);
        self::assertArrayNotHasKey('fin', $resultado['reporte']['totales']);
    }

    public function testUnConsumoFueraDeLaBandaDeFlotaQuedaMarcado(): void
    {
        $resultado = $this->caso(muestras: new SerieDePrueba($this->ronda(litros: 60.0)))->execute($this->actor(), 42);

        $viajes = $resultado['reporte']['viajes'];
        self::assertTrue($viajes[0]['fueraDeBanda'], '60 l/100 km está fuera de la banda de 33 a 41.');
    }

    public function testUnEquipoSinSeriesNoTraeReportes(): void
    {
        $resultado = $this->caso(muestras: new SerieDePrueba())->execute($this->actor(), 42);

        self::assertSame(0, $resultado['reporte']['muestrasLeidas']);
        self::assertSame([], $resultado['reporte']['viajes']);
        self::assertFalse($resultado['reporte']['cobertura']['suficiente']);
        self::assertNotNull($resultado['reporte']['cobertura']['motivo']);
    }

    private function caso(
        SerieMuestrasReader $muestras = new SerieDePrueba(),
        ConsumoConfigStore $config = new ConfigEnMemoria(),
        ?FlotaEnMemoria $flota = null,
    ): GetReporteConsumoFlota {
        return new GetReporteConsumoFlota(
            $flota ?? new FlotaEnMemoria(self::UNIDADES),
            new CalcularConsumoPorEquipo($muestras),
            $config,
            new RelojFijo(),
        );
    }

    /** @param list<string> $permisos @param list<int> $sucursales */
    private function actor(?int $empresaId = 7, array $permisos = ['equipos.ver'], array $sucursales = [], ?bool $todasLasSucursales = true): \App\Application\Identity\ActorContext
    {
        return new \App\Application\Identity\ActorContext(
            1,
            $empresaId,
            false,
            $todasLasSucursales,
            [],
            $permisos,
            $sucursales,
        );
    }

    /** @return list<MuestraEquipo> */
    private function ronda(?float $litros = 90.0): array
    {
        $instante = new DateTimeImmutable('2026-10-10 06:00:00');
        $odometro = 487000;
        $combustible = 500.0;
        $horas = 40625;

        $muestras = [$this->muestra($instante, $odometro, $combustible, $horas)];

        for ($i = 0; $i < 4; $i++) {
            $instante = $instante->modify('+15 minutes');
            $odometro += 75;
            $combustible -= ($litros ?? 0.0) / 4;
            $horas += 15;

            $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);
        }

        return $muestras;
    }

    private function muestra(DateTimeImmutable $instante, int $odometro, ?float $combustible, int $decimas): MuestraEquipo
    {
        return new MuestraEquipo(
            7,
            1,
            42,
            'WIALON-42',
            'wialon',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-32.8051950, -70.0725300, 80.0, 320, 3217.30, 18, $instante),
                $odometro,
                $decimas,
                true,
                false,
                28.52,
                $combustible,
            ),
            $instante,
        );
    }
}