<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Infrastructure\Telematic\ArchivoRespaldoMuestras;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ArchivoRespaldoMuestrasTest extends TestCase
{
    public function testLaVerificacionRechazaUnaLineaJsonlTruncada(): void
    {
        $archivoRelativo = 'writable/telemetria/test-muestras-' . bin2hex(random_bytes(8)) . '.jsonl';
        $archivo = ROOTPATH . str_replace('/', DIRECTORY_SEPARATOR, $archivoRelativo);
        $respaldo = new ArchivoRespaldoMuestras();

        try {
            self::assertSame(1, $respaldo->escribir($archivoRelativo, [$this->muestra()]));
            self::assertTrue($respaldo->verificar($archivoRelativo, 1));
            file_put_contents($archivo, '{"empresa_id":7,"equipo_id":42' . "\n");

            self::assertFalse($respaldo->verificar($archivoRelativo, 1));
        } finally {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }
    }

    private function muestra(): MuestraEquipo
    {
        $instante = new DateTimeImmutable('2026-10-10 12:00:00', new \DateTimeZone('UTC'));

        return new MuestraEquipo(
            7,
            10,
            42,
            'WIALON-42',
            'WIALON',
            new InstantaneaEquipo($instante, null, 1000, 100, true, false, 24.0, 50.0),
            $instante,
        );
    }
}
