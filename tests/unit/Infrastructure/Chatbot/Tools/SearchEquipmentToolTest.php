<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chatbot\Tools;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Chatbot\Tools\SearchEquipmentTool;
use PHPUnit\Framework\TestCase;

final class SearchEquipmentToolTest extends TestCase
{
    public function testReturnsEmptyForEmptyQuery(): void
    {
        $tool = new SearchEquipmentTool($this->createMock(EquipmentListReadModel::class));
        $actor = $this->actor();

        $result = $tool->execute(['query' => ''], $actor);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testReturnsEmptyForSuperAdminWithoutCompany(): void
    {
        $tool = new SearchEquipmentTool($this->createMock(EquipmentListReadModel::class));
        $actor = ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => null,
            'super_admin' => true,
            'all_company_branches' => false,
            'roles' => [],
            'permissions' => [],
            'branch_ids' => [],
        ]);

        $result = $tool->execute(['query' => 'CAM-014'], $actor);
        $this->assertSame([], $result['items']);
    }

    public function testDelegatesToPortWithCorrectCompanyAndBranches(): void
    {
        $port = $this->createMock(EquipmentListReadModel::class);
        $port->expects($this->once())
            ->method('search')
            ->with(
                $this->equalTo(1),
                $this->equalTo([1, 2]),
                $this->equalTo('CAM-014'),
                $this->isNull(),
                $this->isNull(),
                $this->isNull(),
                $this->isNull(),
                $this->equalTo(1),
                $this->equalTo(10),
            )
            ->willReturn(['items' => [['id' => 14, 'codigo' => 'CAM-014', 'patente' => 'AB123CD']], 'total' => 1]);

        $tool = new SearchEquipmentTool($port);
        $result = $tool->execute(['query' => 'CAM-014'], $this->actor());

        $this->assertCount(1, $result['items']);
        $this->assertSame('CAM-014', $result['items'][0]['codigo']);
        $this->assertTrue($result['exact_match']);
    }

    public function testPassesNullBranchesWhenAllCompanyBranches(): void
    {
        $port = $this->createMock(EquipmentListReadModel::class);
        $port->expects($this->once())
            ->method('search')
            ->with(
                $this->equalTo(1),
                $this->isNull(),
                $this->equalTo('CAM-014'),
                $this->isNull(),
                $this->isNull(),
                $this->isNull(),
                $this->isNull(),
                $this->equalTo(1),
                $this->equalTo(10),
            )
            ->willReturn(['items' => [], 'total' => 0]);

        $tool = new SearchEquipmentTool($port);
        $actor = ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => true,
            'roles' => ['admin'],
            'permissions' => ['equipos.ver'],
            'branch_ids' => [1, 2],
        ]);

        $tool->execute(['query' => 'CAM-014'], $actor);
    }

    public function testDoesNotPromotePartialPlateMatchToSelectedEquipment(): void
    {
        $port = $this->createMock(EquipmentListReadModel::class);
        $port->method('search')->willReturn([
            'items' => [[
                'id' => 98,
                'codigo' => 'CA-EX-01',
                'patente' => 'AA000BB',
                'chasis' => null,
            ]],
            'total' => 1,
        ]);

        $result = (new SearchEquipmentTool($port))->execute(['query' => 'AA0000BB'], $this->actor());

        $this->assertFalse($result['exact_match']);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('AA000BB', $result['suggestions'][0]['patente']);
    }

    public function testAcceptsCaseInsensitiveExactPlateMatch(): void
    {
        $port = $this->createMock(EquipmentListReadModel::class);
        $port->method('search')->willReturn([
            'items' => [[
                'id' => 98,
                'codigo' => 'CA-EX-01',
                'patente' => 'AA000BB',
                'chasis' => null,
            ]],
            'total' => 1,
        ]);

        $result = (new SearchEquipmentTool($port))->execute(['query' => 'aa000bb'], $this->actor());

        $this->assertTrue($result['exact_match']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(98, $result['items'][0]['id']);
        $this->assertSame([], $result['suggestions']);
    }

    private function actor(): ActorContext
    {
        return ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['admin'],
            'permissions' => ['equipos.ver'],
            'branch_ids' => [1, 2],
        ]);
    }
}
