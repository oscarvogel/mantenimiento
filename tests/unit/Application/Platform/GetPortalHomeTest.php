<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Platform\GetPlatformModuleCatalog;
use App\Application\Platform\GetPortalHome;
use App\Application\Platform\Port\PortalMaintenanceSummary;
use PHPUnit\Framework\TestCase;

final class GetPortalHomeTest extends TestCase
{
    public function testManagerSeesIndicatorsAndWhatNeedsAttentionWithDrillDownLinks(): void
    {
        $home = $this->home()->execute($this->actor(['planes.ver', 'ordenes.ver', 'equipos.ver']));

        self::assertSame('management', $home['profile']);
        self::assertSame(['overdue', 'due-soon', 'open-orders', 'compliance'], array_column($home['indicators'], 'key'));
        self::assertSame([2, 1, 4, 60], array_column($home['indicators'], 'value'));
        self::assertSame('danger', $home['indicators'][0]['tone']);
        self::assertSame('mantenimiento/planes?estado=VENCIDO', $home['indicators'][0]['href']);
        // Solo lo vencido o próximo; un plan al día no requiere atención.
        self::assertSame(['TR-01 · Service 10.000 km', 'TR-02 · Cambio de aceite', 'AC-07'], array_column($home['attention'], 'title'));
        self::assertSame('mantenimiento/equipos/7', $home['attention'][2]['href']);
        self::assertSame([], $home['shortcuts']);
    }

    public function testIndicatorsFollowPermissionsAndNeverInventCompliance(): void
    {
        $summary = $this->summary(['preventiveCompliance' => null]);
        $home = (new GetPortalHome(new GetPlatformModuleCatalog(), $summary))->execute($this->actor(['planes.ver']));

        self::assertSame(['overdue', 'due-soon', 'compliance'], array_column($home['indicators'], 'key'));
        self::assertNull($home['indicators'][2]['value']);
        // Sin equipos.ver, el aviso de lectura no enlaza a la ficha del equipo.
        self::assertNull($home['attention'][2]['href']);
    }

    public function testFieldUserGetsShortcutsInsteadOfIndicators(): void
    {
        $summary = $this->summary();
        $home = (new GetPortalHome(new GetPlatformModuleCatalog(), $summary))
            ->execute($this->actor(['ordenes.mi_trabajo', 'lecturas.cargar', 'solicitudes.crear']));

        self::assertSame('field', $home['profile']);
        self::assertSame(['my-work', 'readings', 'report-failure'], array_column($home['shortcuts'], 'key'));
        self::assertSame('mantenimiento/ordenes?responsable_id=12&vista=mi-trabajo', $home['shortcuts'][0]['href']);
        self::assertSame([], $home['indicators']);
        self::assertSame(0, $summary->calls, 'El perfil de campo no consulta el tablero.');
    }

    public function testSuperAdminAndUsersWithoutDailyWorkOnlySeeModules(): void
    {
        $summary = $this->summary();
        $portal = new GetPortalHome(new GetPlatformModuleCatalog(), $summary);

        $superAdmin = $portal->execute(new ActorContext(1, null, true, true, ['Superadministrador'], [], []));
        $viewer = $portal->execute($this->actor(['reportes.ver']));

        self::assertSame('modules', $superAdmin['profile']);
        self::assertSame('modules', $viewer['profile']);
        self::assertNotSame([], $viewer['modules']);
        self::assertSame(0, $summary->calls);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(12, 4, false, false, ['Rol'], $permissions, [8]);
    }

    private function home(): GetPortalHome
    {
        return new GetPortalHome(new GetPlatformModuleCatalog(), $this->summary());
    }

    /** @param array<string,mixed> $metrics */
    private function summary(array $metrics = []): PortalMaintenanceSummary
    {
        return new class ($metrics) implements PortalMaintenanceSummary {
            public int $calls = 0;

            /** @param array<string,mixed> $metrics */
            public function __construct(private array $metrics)
            {
            }

            public function fetch(ActorContext $actor): array
            {
                $this->calls++;

                return [
                    'metrics' => [
                        'maintenanceOverdue' => 2,
                        'maintenanceDueSoon' => 1,
                        'openOrders' => 4,
                        'preventiveCompliance' => 60,
                        ...$this->metrics,
                    ],
                    'upcomingMaintenance' => [
                        ['equipmentCode' => 'TR-01', 'serviceName' => 'Service 10.000 km', 'remaining' => 'Vencido hace 300 km', 'branchName' => 'Casa Central', 'status' => 'VENCIDO', 'statusLabel' => 'Vencido'],
                        ['equipmentCode' => 'TR-02', 'serviceName' => 'Cambio de aceite', 'remaining' => 'Faltan 5 días', 'branchName' => '', 'status' => 'PROXIMO', 'statusLabel' => 'Próximo'],
                        ['equipmentCode' => 'TR-03', 'serviceName' => 'Engrase', 'remaining' => 'Faltan 90 días', 'branchName' => '', 'status' => 'AL_DIA', 'statusLabel' => 'Al día'],
                    ],
                    'readingAttention' => [
                        ['equipmentId' => 7, 'equipment' => 'AC-07', 'branchName' => 'Casa Central', 'status' => 'SIN_LECTURA', 'statusLabel' => 'Sin lectura', 'detail' => 'Nunca registró km/horas'],
                    ],
                ];
            }
        };
    }
}
