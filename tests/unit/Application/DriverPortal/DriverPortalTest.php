<?php

declare(strict_types=1);

use App\Application\DriverPortal\GetDriverPortal;
use App\Application\DriverPortal\Port\DriverPortalReadModel;
use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\CreateWorkRequest;
use App\Application\WorkRequests\Port\WorkRequestRepository;
use PHPUnit\Framework\TestCase;

final class DriverPortalTest extends TestCase
{
    public function testMarksReadingPendingAfterSevenDaysAndKeepsEquipmentScoped(): void
    {
        $read = new DriverPortalReadFake('2026-08-24 10:00:00');
        $result = (new GetDriverPortal($read))->execute(
            $this->actor(['equipos.ver', 'lecturas.cargar', 'solicitudes.crear']),
            10,
            new DateTimeImmutable('2026-08-31 10:00:00'),
        );

        self::assertTrue($result['readingPending']);
        self::assertTrue($result['can']['registerReading']);
        self::assertTrue($result['can']['reportIncident']);
        self::assertSame([7], $read->branchIds);
    }

    public function testRecentReadingIsUpToDate(): void
    {
        $result = (new GetDriverPortal(new DriverPortalReadFake('2026-08-30 10:00:00')))->execute(
            $this->actor(['equipos.ver']),
            10,
            new DateTimeImmutable('2026-08-31 10:00:00'),
        );

        self::assertFalse($result['readingPending']);
    }

    public function testIncidentUsesActorTenantBranchAndUser(): void
    {
        $repository = new DriverPortalWorkRequestFake();
        $id = (new CreateWorkRequest($repository))->execute(
            $this->actor(['solicitudes.crear']),
            10,
            'Pierde aire la rueda delantera derecha',
            new DateTimeImmutable('2026-08-31 11:30:00'),
        );

        self::assertSame(91, $id);
        self::assertSame(5, $repository->companyId);
        self::assertSame([7], $repository->branchIds);
        self::assertSame(3, $repository->userId);
        self::assertSame(10, $repository->equipmentId);
    }

    public function testIncidentRejectsEquipmentOutsideScope(): void
    {
        $repository = new DriverPortalWorkRequestFake();
        $repository->result = null;

        $this->expectException(DomainException::class);
        (new CreateWorkRequest($repository))->execute(
            $this->actor(['solicitudes.crear']),
            99,
            'Ruido anormal al frenar',
            new DateTimeImmutable('2026-08-31 11:30:00'),
        );
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(3, 5, false, false, ['Tecnico u operador'], $permissions, [7]);
    }
}

final class DriverPortalReadFake implements DriverPortalReadModel
{
    public ?array $branchIds = null;

    public function __construct(private readonly ?string $lastReadingAt)
    {
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array
    {
        $this->branchIds = $branchIds;
        return [
            'id' => $equipmentId,
            'codigo' => 'AB499OK',
            'patente' => 'AB499OK',
            'tipo_nombre' => 'Camión',
            'sucursal_nombre' => 'TSA Argentina',
            'marca_nombre' => 'Scania',
            'modelo_nombre' => 'R450',
            'estado' => 'ACTIVO',
            'controla_km' => 1,
            'controla_horas' => 0,
            'km_actual' => 990150,
            'horas_actuales' => null,
            'ultima_lectura_fecha' => $this->lastReadingAt,
            'ultima_lectura_km' => 990150,
            'ultima_lectura_horas' => null,
            'ultima_lectura_usuario' => 'Chofer Demo',
        ];
    }
}

final class DriverPortalWorkRequestFake implements WorkRequestRepository
{
    public ?int $result = 91;
    public ?int $companyId = null;
    public ?int $equipmentId = null;
    public ?array $branchIds = null;
    public ?int $userId = null;

    public function createScoped(int $companyId, int $equipmentId, ?array $branchIds, int $userId, string $description, string $reportedAt): ?int
    {
        $this->companyId = $companyId;
        $this->equipmentId = $equipmentId;
        $this->branchIds = $branchIds;
        $this->userId = $userId;
        return $this->result;
    }
}
