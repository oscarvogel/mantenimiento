<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\BucketHistorico;
use App\Infrastructure\Telematic\ArchivoRespaldoBuckets;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArchivoRespaldoBucketsTest extends TestCase
{
    public function testEscribeYVerificaBucketsCompletosEnJsonl(): void
    {
        $nombre = 'writable/telemetria/test-buckets-' . bin2hex(random_bytes(8)) . '.jsonl';
        $ruta = ROOTPATH . str_replace('/', DIRECTORY_SEPARATOR, $nombre);
        $bucket = new BucketHistorico([
            'empresa_id' => 7,
            'integracion_id' => 10,
            'equipo_id' => 42,
            'unidad_externa' => '123',
            'proveedor' => 'wialon',
            'bucket_inicio_utc' => '2026-10-10 12:00:00',
            'bucket_fin_utc' => '2026-10-10 12:05:00',
            'primera_observada_en' => '2026-10-10 12:01:00',
            'ultima_observada_en' => '2026-10-10 12:04:00',
            'segundos_ralenti' => 120,
        ]);
        $respaldo = new ArchivoRespaldoBuckets();

        try {
            self::assertSame(1, $respaldo->escribir($nombre, [$bucket]));
            self::assertTrue($respaldo->verificar($nombre, 1));
            self::assertFalse($respaldo->verificar($nombre, 2));
            $linea = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(120, $linea['segundos_ralenti']);
            self::assertSame('2026-10-10 12:01:00', $linea['primera_observada_en']);
        } finally {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }

    public function testRechazaUnaRutaQueIntentaSalirDeWritable(): void
    {
        $this->expectException(RuntimeException::class);

        (new ArchivoRespaldoBuckets())->escribir('writable/../public/fuga.jsonl', []);
    }

    public function testRechazaUnEnlaceSimbolicoQueSaleDeWritable(): void
    {
        $nombre = 'writable/telemetria/link-' . bin2hex(random_bytes(8));
        $enlace = ROOTPATH . str_replace('/', DIRECTORY_SEPARATOR, $nombre);
        $destino = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'respaldo-externo-' . bin2hex(random_bytes(8));
        mkdir($destino);

        try {
            if (! symlink($destino, $enlace)) {
                self::markTestSkipped('El sistema no permite crear enlaces simbólicos en esta ejecución.');
            }

            try {
                (new ArchivoRespaldoBuckets())->escribir($nombre . '/fuga.jsonl', []);
                self::fail('El destino físico debe permanecer dentro de writable/.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('físicamente dentro de writable', $exception->getMessage());
            }
        } finally {
            if (is_link($enlace)) {
                unlink($enlace);
            }
            rmdir($destino);
        }
    }
}
