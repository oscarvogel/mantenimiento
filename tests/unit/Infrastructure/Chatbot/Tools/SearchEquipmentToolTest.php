<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Chatbot\Tools\SearchEquipmentTool;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

final class SearchEquipmentToolTest extends TestCase
{
    public function testReturnsEmptyForEmptyQuery(): void
    {
        $tool = new SearchEquipmentTool($this->createMock(BaseConnection::class));
        $actor = ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['admin'],
            'permissions' => ['equipos.ver'],
            'branch_ids' => [1],
        ]);

        $this->assertSame([], $tool->execute(['query' => ''], $actor));
    }

    public function testReturnsEmptyWhenActorHasNoCompany(): void
    {
        $tool = new SearchEquipmentTool($this->createMock(BaseConnection::class));
        $actor = ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => null,
            'super_admin' => true,
            'all_company_branches' => false,
            'roles' => [],
            'permissions' => ['equipos.ver'],
            'branch_ids' => [],
        ]);

        $this->assertSame([], $tool->execute(['query' => 'CAM-014'], $actor));
    }
}
