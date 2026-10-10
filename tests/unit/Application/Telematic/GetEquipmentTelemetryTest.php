<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Telematic\GetEquipmentTelemetry;
use App\Application\Telematic\Port\TelemetrySnapshotReader;
use PHPUnit\Framework\TestCase;

/**
 * El caso que llegó a producción: un superadmin no tiene empresa, el lector
 * recibía null y la ficha entera se caía con un error genérico.
 */
final class GetEquipmentTelemetryTest extends TestCase
{
    /** @param list<array<string,mixed>> $filas */
    private function lector(array $filas = []): TelemetrySnapshotReader
    {
        return new class($filas) implements TelemetrySnapshotReader {
            public int $llamadas = 0;

            /** @param list<array<string,mixed>> $filas */
            public function __construct(private readonly array $filas)
            {
            }

            public function forEquipment(?int $companyId, int $equipmentId): array
            {
                $this->llamadas++;

                return $this->filas;
            }
        };
    }

    public function testSinEmpresaNoDevuelveNadaYNoConsultaLaBase(): void
    {
        $lector = $this->lector();
        $caso = new GetEquipmentTelemetry($lector);

        self::assertSame([], $caso->execute(null, 61));
        self::assertSame(0, $lector->llamadas, 'Sin empresa no se toca la base.');
    }

    public function testUnaEmpresaInvalidaTampocoConsulta(): void
    {
        $lector = $this->lector();
        $caso = new GetEquipmentTelemetry($lector);

        self::assertSame([], $caso->execute(0, 61));
        self::assertSame(0, $lector->llamadas);
    }

    public function testUnEquipoInvalidoNoConsulta(): void
    {
        $lector = $this->lector();
        $caso = new GetEquipmentTelemetry($lector);

        self::assertSame([], $caso->execute(4, 0));
        self::assertSame(0, $lector->llamadas);
    }

    public function testConEmpresaYEquipoDevuelveLoDelLector(): void
    {
        $filas = [['integrationId' => 1, 'kilometers' => 1001749]];
        $lector = $this->lector($filas);
        $caso = new GetEquipmentTelemetry($lector);

        $resultado = $caso->execute(4, 28);

        self::assertCount(1, $resultado);
        self::assertSame(1001749, $resultado[0]['kilometers']);
        self::assertSame(1, $lector->llamadas);
    }
}