<?php

declare(strict_types=1);

namespace Tests\unit\Application\Gerencial;

use App\Application\Gerencial\GetTableroFlota;
use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\PreventiveMaintenance\GetServicesEstimadosPorGps;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\LecturaKmVigenteReader;
use App\Application\PreventiveMaintenance\Port\OdometroGpsReader;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Application\Telematic\GetConciliacionCombustible;
use App\Application\Telematic\GetUsoFlota;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Application\Telematic\Port\ViajesDeclaradosReader;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TableroFlotaSpy implements FleetTelemetryBoardReader
{
    /** @var list<?array> */
    public array $lecturas = [];

    public function read(int $companyId, ?array $branchIds): array
    {
        $this->lecturas[] = $branchIds;

        return ['units' => [], 'branches' => [['id' => 9, 'name' => 'Posadas'], ['id' => 10, 'name' => 'Oberá']]];
    }
}

final class TableroSerieVacia implements SerieMuestrasReader
{
    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        return [];
    }
}

final class TableroViajesSpy implements ViajesDeclaradosReader
{
    public int $consultas = 0;

    public function viajesEntre(int $empresaId, ?array $sucursalIds, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->consultas++;

        return [];
    }
}

final class TableroPlanesSpy implements PreventivePlanReadModel
{
    public int $consultas = 0;

    public function listActive(int $companyId, ?array $branchIds): array
    {
        $this->consultas++;

        return [];
    }

    public function listActiveEquipment(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function listActiveServiceTypes(int $companyId): array
    {
        return [];
    }

    public function listActiveBranches(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function listTemplateDefaults(int $companyId): array
    {
        return [];
    }
}

final class TableroLecturasVacias implements LecturaKmVigenteReader
{
    public function vigentes(int $empresaId, array $equipoIds): array
    {
        return [];
    }
}

final class TableroOdometrosVacios implements OdometroGpsReader
{
    public function odometros(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        return [];
    }
}

final class TableroReloj implements NotificationClock, Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-21 12:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}

final class GetTableroFlotaTest extends TestCase
{
    private TableroFlotaSpy $flota;
    private TableroViajesSpy $viajes;
    private TableroPlanesSpy $planes;
    private GetTableroFlota $tablero;

    protected function setUp(): void
    {
        $this->flota = new TableroFlotaSpy();
        $this->viajes = new TableroViajesSpy();
        $this->planes = new TableroPlanesSpy();
        $reloj = new TableroReloj();

        $this->tablero = new GetTableroFlota(
            new GetUsoFlota($this->flota, new TableroSerieVacia(), $reloj),
            new GetConciliacionCombustible($this->viajes, new TableroSerieVacia(), $reloj),
            new GetServicesEstimadosPorGps($this->planes, new TableroLecturasVacias(), new TableroOdometrosVacios(), $reloj),
            $this->flota,
        );
    }

    private function actor(array $permisos = [GetTableroFlota::PERMISO], bool $todas = true, array $sucursales = [], bool $super = false): ActorContext
    {
        return new ActorContext(7, $super ? null : 4, $super, $todas, ['Gerencial'], $permisos, $sucursales);
    }

    public function testSinPermisoNoCalculaNada(): void
    {
        try {
            $this->tablero->execute($this->actor(['gerencial.costo_km.ver']));
            self::fail('Debía rechazar al actor sin permiso.');
        } catch (DomainException) {
            self::assertSame([], $this->flota->lecturas);
        }
    }

    public function testElSuperadministradorNoTieneTableroDeEmpresa(): void
    {
        $this->expectException(DomainException::class);
        $this->tablero->execute($this->actor(super: true));
    }

    public function testPorDefectoMuestraElUsoDeLosUltimosTreintaDias(): void
    {
        $tablero = $this->tablero->execute($this->actor());

        self::assertSame('uso', $tablero['vista']);
        self::assertSame(['dias' => 30, 'sucursalId' => null], $tablero['filtros']);
        self::assertSame(30, $tablero['resultado']['periodo']['dias']);
        self::assertSame([9, 10], array_column($tablero['branches'], 'id'));
        self::assertSame(['uso', 'combustible', 'services'], $tablero['opciones']['vistas']);
    }

    public function testSoloCalculaLaPestanaActiva(): void
    {
        $this->tablero->execute($this->actor(), 'combustible', 7);
        self::assertSame(1, $this->viajes->consultas);
        self::assertSame(0, $this->planes->consultas);

        $tablero = $this->tablero->execute($this->actor(), 'services');
        self::assertSame(1, $this->viajes->consultas);
        self::assertSame(1, $this->planes->consultas);
        self::assertArrayHasKey('planes', $tablero['resultado']);
    }

    public function testUnaVistaInexistenteSeRechaza(): void
    {
        $this->expectException(DomainException::class);
        $this->tablero->execute($this->actor(), 'mapa');
    }

    public function testUnPeriodoFueraDeLasOpcionesSeRechaza(): void
    {
        // Uso admite hasta 31 días: el tablero no ofrece períodos que una pestaña no pueda calcular.
        $this->expectException(DomainException::class);
        $this->tablero->execute($this->actor(), 'uso', 90);
    }

    public function testUnaSucursalAjenaSeRechazaAntesDeLeerLaFlota(): void
    {
        try {
            $this->tablero->execute($this->actor(todas: false, sucursales: [9]), 'uso', 30, 10);
            self::fail('Debía rechazar la sucursal ajena.');
        } catch (DomainException) {
            self::assertSame([], $this->flota->lecturas);
        }
    }

    public function testLasSucursalesDelFiltroSonLasAutorizadas(): void
    {
        $tablero = $this->tablero->execute($this->actor(todas: false, sucursales: [9]), 'services', 30, 9);

        self::assertSame([[9]], $this->flota->lecturas);
        self::assertSame(['dias' => 30, 'sucursalId' => 9], $tablero['filtros']);
    }

    public function testUnActorSinSucursalesNoLeeLaFlota(): void
    {
        $tablero = $this->tablero->execute($this->actor(todas: false, sucursales: []), 'services');

        self::assertSame([], $tablero['branches']);
        self::assertSame([], $this->flota->lecturas);
        self::assertSame(0, $this->planes->consultas);
    }
}
