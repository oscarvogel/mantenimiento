<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Infrastructure\Telematic\WialonEquipmentMatcher;
use PHPUnit\Framework\TestCase;

final class WialonEquipmentMatcherTest extends TestCase
{
    public function testReconoceLaPatenteDentroDelNombreDescriptivoDeWialon(): void
    {
        $result = WialonEquipmentMatcher::match(
            [['id' => 12, 'codigo' => 'AB499OK', 'patente' => 'AB499OK']],
            [['id' => 'unit-4', 'name' => 'SCANIA 360 AB499OK']],
        );

        self::assertSame([[
            'equipmentId' => 12,
            'externalId' => 'unit-4',
            'equipmentCode' => 'AB499OK',
        ]], $result['matches']);
        self::assertSame([], $result['unmatched']);
    }

    public function testReconocePatentesConSeparadoresYSinDistinguirMayusculas(): void
    {
        $result = WialonEquipmentMatcher::match(
            [['id' => 12, 'codigo' => 'AB499OK', 'patente' => 'ab-499-ok']],
            [['id' => 'unit-4', 'name' => 'SCANIA 360 AB 499 OK']],
        );

        self::assertSame('unit-4', $result['matches'][0]['externalId']);
    }

    public function testNoAsociaCoincidenciasAmbiguasNiPatentesIncluidasDentroDeOtraPalabra(): void
    {
        $result = WialonEquipmentMatcher::match(
            [
                ['id' => 12, 'codigo' => 'AB499OK', 'patente' => 'AB499OK'],
                ['id' => 13, 'codigo' => 'AB499OK-2', 'patente' => 'AB499OK'],
                ['id' => 14, 'codigo' => 'AC532DD', 'patente' => 'AC532DD'],
            ],
            [
                ['id' => 'unit-4', 'name' => 'SCANIA 360 AB499OK'],
                ['id' => 'unit-5', 'name' => 'VOLVO 460 AC532DD'],
                ['id' => 'unit-6', 'name' => 'RENAMED-XAC532DDY'],
            ],
        );

        self::assertSame([[
            'equipmentId' => 14,
            'externalId' => 'unit-5',
            'equipmentCode' => 'AC532DD',
        ]], $result['matches']);
        self::assertSame(['AB499OK', 'AB499OK-2'], $result['unmatched']);
    }
}
