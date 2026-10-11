<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\ManageTelemetryEquipmentLinks;
use App\Application\Telematic\Port\TelemetryEquipmentUnitLinkManager;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ManageTelemetryEquipmentLinksTest extends TestCase
{
    public function testGuardaVinculosNormalizadosDelMismoEquipoSinDuplicarUnaUnidad(): void
    {
        $manager = new FakeTelemetryEquipmentUnitLinkManager();
        $useCase = new ManageTelemetryEquipmentLinks($manager);

        $count = $useCase->save(8, 21, 34, ['12' => ' WIALON-5 ', '13' => 'WIALON-6']);

        self::assertSame(2, $count);
        self::assertSame([8, 21, 34, [12 => 'WIALON-5', 13 => 'WIALON-6']], $manager->saved);
    }

    public function testRechazaUnaUnidadRepetidaAntesDePersistir(): void
    {
        $manager = new FakeTelemetryEquipmentUnitLinkManager();

        try {
            (new ManageTelemetryEquipmentLinks($manager))->save(8, 21, 34, [12 => 'UNIT-5', 13 => 'UNIT-5']);
            self::fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            self::assertSame('Cada unidad de Wialon se puede vincular a un solo equipo.', $exception->getMessage());
        }

        self::assertNull($manager->saved);
    }

    public function testRechazaIdentificadoresDeEquipoQueNoSeanValidos(): void
    {
        $manager = new FakeTelemetryEquipmentUnitLinkManager();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La selección contiene un equipo inválido.');

        (new ManageTelemetryEquipmentLinks($manager))->save(8, 21, 34, ['fuera-de-empresa' => 'UNIT-5']);
    }
}

final class FakeTelemetryEquipmentUnitLinkManager implements TelemetryEquipmentUnitLinkManager
{
    /** @var list<mixed>|null */
    public ?array $saved = null;

    public function snapshotFor(int $companyId, int $integrationId): array
    {
        return ['integration' => [], 'equipment' => [], 'units' => [], 'links' => []];
    }

    /** @param array<int,string> $assignments */
    public function saveFor(int $companyId, int $userId, int $integrationId, array $assignments): int
    {
        $this->saved = [$companyId, $userId, $integrationId, $assignments];

        return count($assignments);
    }
}
