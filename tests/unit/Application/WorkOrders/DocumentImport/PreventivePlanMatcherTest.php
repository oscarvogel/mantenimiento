<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\PreventivePlanMatcher;
use PHPUnit\Framework\TestCase;

final class PreventivePlanMatcherTest extends TestCase
{
    public function testMatchesOnlyTasksWithDocumentEvidence(): void
    {
        $matcher = new PreventivePlanMatcher();
        $plans = [[
            'id' => 10,
            'tipo_servicio_id' => 5,
            'servicio_nombre' => 'Service motor',
        ]];
        $services = [[
            'id' => 5,
            'tasks' => [
                [
                    'id' => 101,
                    'name' => 'Cambiar filtro de aceite',
                    'mandatory' => true,
                    'active' => true,
                    'materials' => [['description' => 'Filtro de aceite', 'active' => true]],
                ],
                [
                    'id' => 102,
                    'name' => 'Cambiar filtro de combustible',
                    'mandatory' => true,
                    'active' => true,
                    'materials' => [['description' => 'Filtro de combustible', 'active' => true]],
                ],
                [
                    'id' => 103,
                    'name' => 'Controlar nivel de refrigerante',
                    'mandatory' => true,
                    'active' => true,
                    'materials' => [],
                ],
            ],
        ]];
        $works = [
            ['description' => 'Hacer service motor', 'classification' => 'preventivo', 'included' => true],
            ['description' => 'Cambiar filtros de aceite', 'classification' => 'preventivo', 'included' => true],
        ];
        $materials = [
            ['description' => 'Filtro de aceite', 'quantity' => 3],
            ['description' => 'Filtro de combustible', 'quantity' => 2],
        ];

        $result = $matcher->match($plans, $services, $works, $materials);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]['evidencedTaskCount']);
        self::assertFalse($result[0]['requiredTasksEvidenced']);
        self::assertCount(3, $result[0]['taskMatches']);
        self::assertTrue($result[0]['taskMatches'][0]['evidenced']);
        self::assertTrue($result[0]['taskMatches'][1]['evidenced']);
        self::assertFalse($result[0]['taskMatches'][2]['evidenced']);
        self::assertTrue($result[0]['suggested']);
    }

    public function testIgnoresCorrectiveWorksWhenMatchingPreventiveTasks(): void
    {
        $matcher = new PreventivePlanMatcher();
        $tasks = [[
            'id' => 201,
            'name' => 'Revisar válvula secadora',
            'required' => true,
        ]];
        $works = [[
            'description' => 'Revisar válvula secadora por pérdida de aire',
            'classification' => 'correctivo',
            'included' => true,
        ]];

        $matches = $matcher->matchTasks($tasks, $works, []);

        self::assertCount(1, $matches);
        self::assertFalse($matches[0]['evidenced']);
        self::assertSame(0.0, $matches[0]['score']);
    }

    public function testExcludedPreventiveWorkDoesNotCountAsEvidence(): void
    {
        $matcher = new PreventivePlanMatcher();
        $tasks = [[
            'id' => 301,
            'name' => 'Cambiar correas Poly V',
            'required' => false,
        ]];
        $works = [[
            'description' => 'Cambiar correas Poly V',
            'classification' => 'preventivo',
            'included' => false,
        ]];

        $matches = $matcher->matchTasks($tasks, $works, []);

        self::assertFalse($matches[0]['evidenced']);
    }
}
