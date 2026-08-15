<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\PreventiveLibraryPayload;
use PHPUnit\Framework\TestCase;

final class PreventiveLibraryPayloadTest extends TestCase
{
    public function testAddsTaskActionUrlsWhenTheReadModelAlreadyContainsTasks(): void
    {
        $items = (new PreventiveLibraryPayload())->items([
            [
                'id' => 12,
                'serviceTypeId' => 4,
                'tasks' => [[
                    'id' => 91,
                    'updateUrl' => null,
                ]],
            ],
        ], 'http://localhost/mantenimiento/importaciones');

        self::assertSame(
            'http://localhost/mantenimiento/importaciones/biblioteca/tareas/91',
            $items[0]['tasks'][0]['updateUrl'],
        );
        self::assertSame(4, $items[0]['tasks'][0]['serviceTypeId']);
    }
}
