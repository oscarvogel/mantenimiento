<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\ClosePreventiveOrder;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderClosurePort;
use CodeIgniter\Test\CIUnitTestCase;

final class ClosePreventiveOrderTest extends CIUnitTestCase
{
    public function testPassesNormalizedClosureAndRestrictedScopeToAtomicPort(): void
    {
        $port = new class implements PreventiveOrderClosurePort {
            public array $arguments = [];

            public function close(int $companyId, ?array $branchIds, int $orderId, array $closure, int $actorUserId): array
            {
                $this->arguments = [$companyId, $branchIds, $orderId, $closure, $actorUserId];

                return ['numero' => 'OT-2026-000001', 'proximo_km' => 12000];
            }
        };
        $actor = new ActorContext(9, 3, false, false, ['Responsable'], ['ordenes.cerrar'], [5]);

        $result = (new ClosePreventiveOrder($port))->execute($actor, 12, [
            'trabajo_realizado' => [
                '41' => ['resultado' => 'REALIZADA', 'detalle' => 'Filtro de aceite reemplazado'],
                '42' => ['resultado' => 'PENDIENTE', 'detalle' => 'No había repuesto disponible'],
                '43' => ['resultado' => 'NO_APLICA', 'detalle' => 'No corresponde para esta unidad'],
            ],
            'fecha_servicio'     => '2026-08-08',
            'km_salida'          => '10000',
            'horas_salida'       => '125,4',
            'observaciones'      => ' Sin novedades ',
        ]);

        $this->assertSame('OT-2026-000001', $result['numero']);
        $this->assertSame(3, $port->arguments[0]);
        $this->assertSame([5], $port->arguments[1]);
        $this->assertSame(12, $port->arguments[2]);
        $this->assertSame('REALIZADA', $port->arguments[3]['tareas'][41]['resultado']);
        $this->assertSame('PENDIENTE', $port->arguments[3]['tareas'][42]['resultado']);
        $this->assertSame('No había repuesto disponible', $port->arguments[3]['tareas'][42]['detalle']);
        $this->assertSame('125.4', $port->arguments[3]['horas_salida']);
        $this->assertSame(9, $port->arguments[4]);
    }

    public function testKeepsLegacyGlobalWorkTextForCompatibility(): void
    {
        $port = new class implements PreventiveOrderClosurePort {
            public array $closure = [];

            public function close(int $companyId, ?array $branchIds, int $orderId, array $closure, int $actorUserId): array
            {
                $this->closure = $closure;
                return ['numero' => 'OT-2026-000001'];
            }
        };
        $actor = new ActorContext(9, 3, false, true, ['Administrador'], ['ordenes.cerrar'], []);

        (new ClosePreventiveOrder($port))->execute($actor, 12, [
            'trabajo_realizado' => ' Cambio de filtros ',
            'fecha_servicio' => '2026-08-08',
        ]);

        $this->assertSame('Cambio de filtros', $port->closure['trabajo_realizado']);
        $this->assertNull($port->closure['tareas']);
    }

    public function testRejectsTaskClosureWithoutAnyPerformedTask(): void
    {
        $port = new class implements PreventiveOrderClosurePort {
            public function close(int $companyId, ?array $branchIds, int $orderId, array $closure, int $actorUserId): array
            {
                self::fail('The port must not be called.');
            }
        };
        $actor = new ActorContext(9, 3, false, true, ['Administrador'], ['ordenes.cerrar'], []);

        $this->expectException(DomainException::class);
        (new ClosePreventiveOrder($port))->execute($actor, 12, [
            'trabajo_realizado' => [
                '41' => ['resultado' => 'PENDIENTE', 'detalle' => 'Sin repuesto disponible'],
            ],
            'fecha_servicio' => '2026-08-08',
        ]);
    }

    public function testRejectsIncompleteClosureBeforeCallingPort(): void
    {
        $port = new class implements PreventiveOrderClosurePort {
            public function close(int $companyId, ?array $branchIds, int $orderId, array $closure, int $actorUserId): array
            {
                self::fail('The port must not be called.');
            }
        };
        $actor = new ActorContext(9, 3, false, true, ['Administrador'], ['ordenes.cerrar'], []);

        $this->expectException(DomainException::class);
        (new ClosePreventiveOrder($port))->execute($actor, 12, ['fecha_servicio' => '2026-08-08']);
    }
}
