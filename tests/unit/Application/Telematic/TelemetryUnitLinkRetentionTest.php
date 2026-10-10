<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\TelemetryUnitLinkRetention;
use PHPUnit\Framework\TestCase;

final class TelemetryUnitLinkRetentionTest extends TestCase
{
    public function testSoloMarcaParaDesactivarLosVinculosActivosCuyaUnidadYaNoEstaEnLaCuenta(): void
    {
        $stale = TelemetryUnitLinkRetention::obsoleteUnitIds(
            [['id' => 'unit-1', 'name' => 'Camión 1']],
            [
                ['unidad_externa' => 'unit-1', 'activo' => 1],
                ['unidad_externa' => 'unit-old', 'activo' => 1],
                ['unidad_externa' => 'unit-inactive', 'activo' => 0],
            ],
        );

        self::assertSame(['unit-old'], $stale);
    }
}
