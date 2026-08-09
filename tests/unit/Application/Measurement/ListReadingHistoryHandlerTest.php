<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Measurement\ListReadingHistoryHandler;
use App\Application\Measurement\ListReadingHistoryQuery;
use App\Application\Measurement\Port\ReadingHistoryPort;
use App\Application\Measurement\ReadingHistoryPage;
use PHPUnit\Framework\TestCase;

final class ListReadingHistoryHandlerTest extends TestCase
{
    public function testDelegatesPaginatedScopedHistoryForCurrentEquipmentBranch(): void
    {
        $port = new ReadingHistoryPortFake();
        $handler = new ListReadingHistoryHandler($port);

        $result = $handler->execute(
            new ActorContext(9, 5, false, false, ['Técnico'], ['lecturas.ver'], [9]),
            new ListReadingHistoryQuery(10, 2, 30),
        );

        self::assertSame(51, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(30, $result->perPage);
        self::assertSame([9], $port->branches);
        self::assertSame(10, $port->equipmentId);
    }

    public function testAdministratorUsesAllTenantBranches(): void
    {
        $port = new ReadingHistoryPortFake();
        (new ListReadingHistoryHandler($port))->execute(
            new ActorContext(9, 5, false, true, ['Administrador'], ['lecturas.ver'], []),
            new ListReadingHistoryQuery(10),
        );

        self::assertNull($port->branches);
    }

    public function testRejectsMoreThanOneHundredRowsPerPage(): void
    {
        $this->expectException(DomainException::class);
        (new ListReadingHistoryHandler(new ReadingHistoryPortFake()))->execute(
            new ActorContext(9, 5, false, true, ['Administrador'], ['lecturas.ver'], []),
            new ListReadingHistoryQuery(10, 1, 101),
        );
    }

    public function testRequiresDedicatedViewPermission(): void
    {
        $this->expectException(DomainException::class);
        (new ListReadingHistoryHandler(new ReadingHistoryPortFake()))->execute(
            new ActorContext(9, 5, false, true, ['Administrador'], ['lecturas.cargar'], []),
            new ListReadingHistoryQuery(10),
        );
    }
}

final class ReadingHistoryPortFake implements ReadingHistoryPort
{
    public int $equipmentId = 0;
    public ?array $branches = [];
    public function forEquipment(int $companyId, int $equipmentId, ?array $authorizedBranchIds, int $page, int $perPage): ReadingHistoryPage
    {
        $this->equipmentId = $equipmentId;
        $this->branches = $authorizedBranchIds;
        return new ReadingHistoryPage([], 51, $page, $perPage);
    }
}
