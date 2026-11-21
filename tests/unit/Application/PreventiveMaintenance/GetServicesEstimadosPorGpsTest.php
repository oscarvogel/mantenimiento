<?php

declare(strict_types=1);

namespace Tests\unit\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\GetServicesEstimadosPorGps;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\LecturaKmVigenteReader;
use App\Application\PreventiveMaintenance\Port\OdometroGpsReader;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Application\PreventiveMaintenance\PreventivePlanListItem;
use App\Domain\PreventiveMaintenance\Gps\LecturaKmVigente;
use App\Domain\PreventiveMaintenance\Gps\OdometroGps;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PlanesGpsEnMemoria implements PreventivePlanReadModel
{
    /** @var list<array{empresa:int, sucursales:?array}> */
    public array $consultas = [];

    /** @param list<PreventivePlanListItem> $items */
    public function __construct(private array $items = [])
    {
    }

    public function listActive(int $companyId, ?array $branchIds): array
    {
        $this->consultas[] = ['empresa' => $companyId, 'sucursales' => $branchIds];

        return array_values(array_filter(
            $this->items,
            static fn (PreventivePlanListItem $i): bool => $branchIds === null || in_array($i->branchId, $branchIds, true),
        ));
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

final class LecturasGpsEnMemoria implements LecturaKmVigenteReader
{
    /** @var list<array{empresa:int, equipos:list<int>}> */
    public array $consultas = [];

    /** @param array<int, LecturaKmVigente> $lecturas */
    public function __construct(private array $lecturas = [])
    {
    }

    public function vigentes(int $empresaId, array $equipoIds): array
    {
        $this->consultas[] = ['empresa' => $empresaId, 'equipos' => $equipoIds];

        return array_intersect_key($this->lecturas, array_flip($equipoIds));
    }
}

final class OdometrosGpsEnMemoria implements OdometroGpsReader
{
    /** @var list<array{empresa:int, equipo:int, desde:string}> */
    public array $consultas = [];

    /** @param array<int, list<OdometroGps>> $odometros */
    public function __construct(private array $odometros = [])
    {
    }

    public function odometros(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->consultas[] = ['empresa' => $empresaId, 'equipo' => $equipoId, 'desde' => $desde->format('Y-m-d H:i')];

        return $this->odometros[$equipoId] ?? [];
    }
}

final class RelojGpsFijo implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-20 20:05', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}

final class GetServicesEstimadosPorGpsTest extends TestCase
{
    private static function en(string $fecha): DateTimeImmutable
    {
        return new DateTimeImmutable($fecha, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private function actor(array $permisos = [GetServicesEstimadosPorGps::PERMISO], bool $todas = true, array $sucursales = [], bool $super = false): ActorContext
    {
        return new ActorContext(7, $super ? null : 4, $super, $todas, ['Gerencial'], $permisos, $sucursales);
    }

    private function item(int $planId, int $equipoId, int $sucursal, string $patente, ?int $kmActual, ?int $proximoKm, ?int $intervaloKm = 35000): PreventivePlanListItem
    {
        $porKm = $intervaloKm !== null;
        $plan = PlanMantenimiento::reconstituir(
            $planId, 4, $equipoId, 1,
            $intervaloKm, null, $porKm ? null : 120,
            $porKm ? 5000 : null, null, $porKm ? null : 10,
            $porKm && $proximoKm !== null ? $proximoKm - $intervaloKm : null, null, $porKm ? null : self::en('2026-09-30'),
            $porKm ? $proximoKm : null, null, $porKm ? null : self::en('2027-01-28'),
            'MEDIA', true, null,
        );

        return new PreventivePlanListItem($plan, new UsoActual($kmActual, null), 'T' . $equipoId, $patente, $sucursal, 'S' . $sucursal, 'Sucursal ' . $sucursal, 'Tractor', 'Service motor');
    }

    /** @return array{0:GetServicesEstimadosPorGps, 1:PlanesGpsEnMemoria, 2:LecturasGpsEnMemoria, 3:OdometrosGpsEnMemoria} */
    private function caso(array $items, array $lecturas = [], array $odometros = []): array
    {
        $planes = new PlanesGpsEnMemoria($items);
        $lectorLecturas = new LecturasGpsEnMemoria($lecturas);
        $gps = new OdometrosGpsEnMemoria($odometros);

        return [new GetServicesEstimadosPorGps($planes, $lectorLecturas, $gps, new RelojGpsFijo()), $planes, $lectorLecturas, $gps];
    }

    public function testSinPermisoGerencialNoConsulta(): void
    {
        [$caso, $planes] = $this->caso([]);

        try {
            $caso->execute($this->actor(['planes.ver']));
            self::fail('Debía rechazar al actor sin permiso.');
        } catch (DomainException) {
            self::assertSame([], $planes->consultas);
        }
    }

    public function testElSuperadministradorNoConsulta(): void
    {
        [$caso] = $this->caso([]);

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(super: true));
    }

    public function testUnaSucursalAjenaSeRechaza(): void
    {
        [$caso] = $this->caso([]);

        $this->expectException(DomainException::class);
        $caso->execute($this->actor(todas: false, sucursales: [1]), 2);
    }

    public function testUnActorSinSucursalesNoLeeNada(): void
    {
        [$caso, $planes, $lecturas] = $this->caso([$this->item(1, 1, 1, 'AB499OK', 1013505, 1020624)]);

        $resultado = $caso->execute($this->actor(todas: false, sucursales: []));

        self::assertSame([], $planes->consultas);
        self::assertSame([], $lecturas->consultas);
        self::assertSame(0, $resultado['totales']['planes']);
    }

    public function testElGpsAdelantaElServiceSinTocarElEstadoReal(): void
    {
        [$caso, , , $gps] = $this->caso(
            [$this->item(1, 1, 1, 'AB499OK', 1013505, 1020624)],
            [1 => new LecturaKmVigente(1, 1013505, self::en('2026-11-16 12:48'))],
            [1 => [new OdometroGps(self::en('2026-11-16 12:30'), 1009809), new OdometroGps(self::en('2026-11-20 20:00'), 1014197)]],
        );

        $fila = $caso->execute($this->actor())['planes'][0];

        self::assertSame(['km' => 1013505, 'kmRestantes' => 7119, 'estado' => 'AL_DIA'], $fila['real']);
        self::assertSame(1017893, $fila['estimado']['km']);
        self::assertSame(2731, $fila['estimado']['kmRestantes']);
        self::assertSame('PROXIMO', $fila['estimado']['estado']);
        self::assertSame(['KILOMETRAJE'], $fila['estimado']['criterios']);
        self::assertTrue($fila['seAdelanta']);
        self::assertSame(4388, $fila['gps']['kmDesdeLectura']);
        self::assertSame('2026-11-20 20:00', $fila['gps']['hasta']);
        self::assertSame(['km' => 1013505, 'fecha' => '2026-11-16 12:48'], $fila['lectura']);
        self::assertNull($fila['motivo']);
        // La ventana del GPS arranca 6 h antes de la lectura y la pide la empresa del actor.
        self::assertSame([['empresa' => 4, 'equipo' => 1, 'desde' => '2026-11-16 06:48']], $gps->consultas);
    }

    public function testSinLecturaVigenteQuedaSinEstimacionConMotivoYNoLeeElGps(): void
    {
        [$caso, , , $gps] = $this->caso([$this->item(1, 1, 1, 'GZD587', null, 200000)]);

        $fila = $caso->execute($this->actor())['planes'][0];

        self::assertNull($fila['estimado']);
        self::assertFalse($fila['seAdelanta']);
        self::assertSame('No hay una lectura vigente del odómetro.', $fila['motivo']);
        self::assertSame([], $gps->consultas);
    }

    public function testLosPlanesSinKmNoEntranYCadaEquipoSeEstimaUnaVez(): void
    {
        [$caso, , $lecturas, $gps] = $this->caso(
            [
                $this->item(1, 1, 1, 'AB499OK', 1013505, 1020624),
                $this->item(2, 1, 1, 'AB499OK', 1013505, 1040624),
                $this->item(3, 1, 1, 'AB499OK', 1013505, null, null),
            ],
            [1 => new LecturaKmVigente(1, 1013505, self::en('2026-11-16 12:48'))],
        );

        $resultado = $caso->execute($this->actor());

        self::assertSame([1, 2], array_column($resultado['planes'], 'planId'));
        self::assertSame([['empresa' => 4, 'equipos' => [1]]], $lecturas->consultas);
        self::assertCount(1, $gps->consultas);
    }

    public function testLaSucursalRecortaYLosTotalesCuentanLoEstimado(): void
    {
        $gpsUno = [new OdometroGps(self::en('2026-11-16 12:30'), 1009809), new OdometroGps(self::en('2026-11-20 20:00'), 1014197)];
        [$caso, $planes] = $this->caso(
            [
                $this->item(1, 1, 1, 'AB499OK', 1013505, 1020624),
                $this->item(2, 2, 1, 'AC532DD', 1043494, 1040980),
                $this->item(3, 3, 1, 'GZD587', null, 200000),
                $this->item(4, 4, 2, 'JLH877', 1729288, 1742064),
            ],
            [
                1 => new LecturaKmVigente(1, 1013505, self::en('2026-11-16 12:48')),
                2 => new LecturaKmVigente(2, 1043494, self::en('2026-11-20 09:57')),
            ],
            [1 => $gpsUno, 2 => [new OdometroGps(self::en('2026-11-20 09:45'), 1039537)]],
        );

        $resultado = $caso->execute($this->actor(todas: false, sucursales: [1, 2]), 1);

        self::assertSame([['empresa' => 4, 'sucursales' => [1]]], $planes->consultas);
        // El vencido primero (menos km restantes), sin estimación al final.
        self::assertSame(['AC532DD', 'AB499OK', 'GZD587'], array_column($resultado['planes'], 'patente'));
        self::assertSame([
            'planes' => 3,
            'conEstimacion' => 2,
            'sinEstimacion' => 1,
            'porEstadoEstimado' => ['SIN_DATOS' => 0, 'AL_DIA' => 0, 'PROXIMO' => 1, 'VENCIDO' => 1],
            'seAdelantan' => 1,
        ], $resultado['totales']);
    }
}
