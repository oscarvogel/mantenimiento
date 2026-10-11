<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\TelemetryBucketStore;
use App\Application\Telematic\SincronizarBucketsTelemetria;
use App\Domain\Telematic\AgregadorBucketsTelemetria;
use App\Domain\Telematic\BucketTelemetria;
use App\Domain\Telematic\MensajeHistorico;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LectorHistoricoEnMemoria implements LectorHistoricoTelemetria
{
    /** @var list<array{unidad:string, desde:DateTimeImmutable, hasta:DateTimeImmutable}> */
    public array $ventanas = [];

    /** @param array<string,list<MensajeHistorico>> $mensajes */
    public function __construct(private array $mensajes = [], private bool $fallar = false)
    {
    }

    public function mensajesEntre(int $integracionId, string $unidadExterna, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->ventanas[] = ['unidad' => $unidadExterna, 'desde' => $desde, 'hasta' => $hasta];
        if ($this->fallar) {
            throw new RuntimeException('Wialon no respondió');
        }

        return $this->mensajes[$unidadExterna] ?? [];
    }
}

final class BucketsEnMemoria implements TelemetryBucketStore
{
    /** @var array<string,DateTimeImmutable> */
    public array $cursores = [];

    /** @var array<string,list<BucketTelemetria>> */
    public array $guardados = [];

    public function cursor(int $empresaId, int $integracionId, string $unidadExterna): ?DateTimeImmutable
    {
        return $this->cursores[$empresaId . ':' . $integracionId . ':' . $unidadExterna] ?? null;
    }

    public function guardarCerradosYAvanzarCursor(int $empresaId, int $integracionId, int $equipoId, string $unidadExterna, string $proveedor, array $buckets, DateTimeImmutable $cursorHasta, DateTimeImmutable $registradaEn): array
    {
        $clave = $empresaId . ':' . $integracionId . ':' . $unidadExterna;
        $existentes = $this->guardados[$clave] ?? [];
        $porInicio = [];
        foreach ($existentes as $bucket) {
            $porInicio[$bucket->inicio()->getTimestamp()] = $bucket;
        }

        $insertados = 0;
        foreach ($buckets as $bucket) {
            if (isset($porInicio[$bucket->inicio()->getTimestamp()])) {
                continue;
            }
            $porInicio[$bucket->inicio()->getTimestamp()] = $bucket;
            $insertados++;
        }
        ksort($porInicio);
        $this->guardados[$clave] = array_values($porInicio);
        $this->cursores[$clave] = $cursorHasta;

        return ['insertados' => $insertados, 'yaExistentes' => count($buckets) - $insertados];
    }
}

final class SincronizarBucketsTelemetriaTest extends TestCase
{
    private function mensaje(string $fecha, float $litros): MensajeHistorico
    {
        return new MensajeHistorico(new DateTimeImmutable($fecha, new \DateTimeZone('UTC')), $litros, 1000, 10, true, false);
    }

    public function testPrimeraConsultaSeLimitaAlUltimoIntervaloCerradoDeCincoMinutos(): void
    {
        $ahora = new DateTimeImmutable('2026-10-10 10:12:34', new \DateTimeZone('UTC'));
        $lector = new LectorHistoricoEnMemoria();
        $store = new BucketsEnMemoria();
        $sync = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());

        self::assertSame(['insertados' => 0, 'yaExistentes' => 0], $sync->sincronizar(4, 8, 'wialon', ['28396292' => 34], $ahora));

        $ventana = $lector->ventanas[0];
        self::assertSame('28396292', $ventana['unidad']);
        self::assertLessThanOrEqual($ahora->getTimestamp(), $ventana['hasta']->getTimestamp());
        self::assertSame(0, $ventana['desde']->getTimestamp() % 300);
        self::assertSame(0, $ventana['hasta']->getTimestamp() % 300);
        self::assertEquals(new DateTimeImmutable('2026-10-10 10:10:00', new \DateTimeZone('UTC')), $store->cursor(4, 8, '28396292'));
        self::assertSame(300, $ventana['hasta']->getTimestamp() - $ventana['desde']->getTimestamp());
    }

    public function testProcesaUnicamenteUnidadesVinculadasYAvanzaCursorAnteRespuestaVacia(): void
    {
        $lector = new LectorHistoricoEnMemoria();
        $store = new BucketsEnMemoria();
        $sync = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());

        $sync->sincronizar(4, 8, 'wialon', ['28396292' => 34], new DateTimeImmutable('2026-10-10 10:12:00', new \DateTimeZone('UTC')));

        self::assertSame(['28396292'], array_column($lector->ventanas, 'unidad'));
        self::assertNotNull($store->cursor(4, 8, '28396292'));
        self::assertNull($store->cursor(4, 8, '999999'));
    }

    public function testVentanaIncrementalTieneSolapeDeCincoMinutosParaRecuperarMensajesTardios(): void
    {
        $store = new BucketsEnMemoria();
        $store->cursores['4:8:28396292'] = new DateTimeImmutable('2026-10-10 10:00:00', new \DateTimeZone('UTC'));
        $lector = new LectorHistoricoEnMemoria();
        $sync = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());

        $sync->sincronizar(4, 8, 'wialon', ['28396292' => 34], new DateTimeImmutable('2026-10-10 10:12:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-10 09:55:00', $lector->ventanas[0]['desde']->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 10:10:00', $lector->ventanas[0]['hasta']->format('Y-m-d H:i:s'));
    }

    public function testUnFalloDelLectorNoAvanzaElCursorNiSeInterpretaComoVentanaVacia(): void
    {
        $lector = new LectorHistoricoEnMemoria(fallar: true);
        $store = new BucketsEnMemoria();
        $sync = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());

        try {
            $sync->sincronizar(4, 8, 'wialon', ['28396292' => 34], new DateTimeImmutable('2026-10-10 10:12:00', new \DateTimeZone('UTC')));
            self::fail('El error de lectura debe propagarse.');
        } catch (RuntimeException $exception) {
            self::assertSame('Wialon no respondió', $exception->getMessage());
        }

        self::assertNull($store->cursor(4, 8, '28396292'));
    }

    public function testReintentoEsIdempotenteYNoReescribeUnBucketCerrado(): void
    {
        $mensajes = [
            '28396292' => [
                $this->mensaje('2026-10-10 10:01:00', 90),
                $this->mensaje('2026-10-10 10:04:00', 80),
                $this->mensaje('2026-10-10 10:06:00', 70),
            ],
        ];
        $lector = new LectorHistoricoEnMemoria($mensajes);
        $store = new BucketsEnMemoria();
        $sync = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());
        $ahora = new DateTimeImmutable('2026-10-10 10:12:00', new \DateTimeZone('UTC'));

        self::assertSame(['insertados' => 1, 'yaExistentes' => 0], $sync->sincronizar(4, 8, 'wialon', ['28396292' => 34], $ahora));
        $mensajes['28396292'][1] = $this->mensaje('2026-10-10 10:04:00', 1);
        $lector = new LectorHistoricoEnMemoria($mensajes);
        $retry = new SincronizarBucketsTelemetria($lector, $store, new AgregadorBucketsTelemetria());
        self::assertSame(['insertados' => 0, 'yaExistentes' => 1], $retry->sincronizar(4, 8, 'wialon', ['28396292' => 34], $ahora));
        self::assertSame(90.0, $store->guardados['4:8:28396292'][0]->primero()->combustibleLitros());
        self::assertSame(80.0, $store->guardados['4:8:28396292'][0]->ultimo()->combustibleLitros());
    }
}
