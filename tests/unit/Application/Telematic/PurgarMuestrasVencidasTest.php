<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\Port\HistoricoMuestras;
use App\Application\Telematic\BucketHistorico;
use App\Application\Telematic\Port\HistoricoBuckets;
use App\Application\Telematic\Port\RespaldoBuckets;
use App\Application\Telematic\Port\RespaldoMuestras;
use App\Application\Telematic\PurgarMuestrasVencidas;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Histórico en memoria que anota el orden de las operaciones. */
final class MemoriaHistorico implements HistoricoMuestras
{
    /** @var list<string> */
    public array $operaciones = [];

    /** @var list<MuestraEquipo> */
    private array $vencidas;

    private int $borradas;

    /** @param list<MuestraEquipo> $vencidas */
    public function __construct(array $vencidas, ?int $borradas = null)
    {
        $this->vencidas = $vencidas;
        $this->borradas = $borradas ?? count($vencidas);
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        $this->operaciones[] = 'leer';

        return $this->vencidas;
    }

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int
    {
        $this->operaciones[] = 'borrar';

        return $this->borradas;
    }
}

/** Respaldo en memoria con la posibilidad de fallar a propósito. */
final class MemoriaRespaldo implements RespaldoMuestras
{
    /** @var list<string> */
    public array $operaciones = [];

    public function __construct(
        private readonly int $escritas,
        private readonly bool $verificado,
    ) {
    }

    public function escribir(string $archivo, array $muestras): int
    {
        $this->operaciones[] = 'escribir';

        return $this->escritas;
    }

    public function verificar(string $archivo, int $esperadas): bool
    {
        $this->operaciones[] = 'verificar';

        return $this->verificado;
    }
}

final class MemoriaBucketsHistoricos implements HistoricoBuckets
{
    /** @var list<string> */
    public array $operaciones = [];

    /** @param list<BucketHistorico> $buckets */
    public function __construct(private readonly array $buckets, private readonly ?int $borrados = null)
    {
    }

    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array
    {
        $this->operaciones[] = 'leer';

        return $this->buckets;
    }

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int
    {
        $this->operaciones[] = 'borrar';

        return $this->borrados ?? count($this->buckets);
    }
}

final class MemoriaRespaldoBuckets implements RespaldoBuckets
{
    /** @var list<string> */
    public array $operaciones = [];

    public function __construct(private readonly int $escritos, private readonly bool $verificado)
    {
    }

    public function escribir(string $archivo, array $buckets): int
    {
        $this->operaciones[] = 'escribir:' . $archivo;

        return $this->escritos;
    }

    public function verificar(string $archivo, int $esperados): bool
    {
        $this->operaciones[] = 'verificar:' . $archivo;

        return $this->verificado;
    }
}

/**
 * La purga borra histórico irreversible, así que el orden de las operaciones
 * es el comportamiento que hay que probar, no un detalle de implementación.
 *
 * @internal
 */
final class PurgarMuestrasVencidasTest extends TestCase
{
    private const AHORA = '2026-10-10 12:00:00';

    public function testSinMuestrasVencidasNoRespaldaNiBorra(): void
    {
        $historico = new MemoriaHistorico([]);
        $respaldo = new MemoriaRespaldo(0, true);

        $resultado = (new PurgarMuestrasVencidas($historico, $respaldo, 7))->execute(
            new DateTimeImmutable(self::AHORA),
            'writable/telemetria/respaldo.jsonl',
        );

        self::assertSame(0, $resultado['borradas']);
        self::assertSame(['leer'], $historico->operaciones);
        self::assertSame([], $respaldo->operaciones, 'Sin nada que purgar no se crea un respaldo vacío.');
    }

    public function testElCorteEsLaRetencionMenosDias(): void
    {
        $purga = new PurgarMuestrasVencidas(new MemoriaHistorico([]), new MemoriaRespaldo(0, true), 7, 90);

        self::assertSame(
            '2026-07-12 15:00:00',
            $purga->corte(new DateTimeImmutable(self::AHORA, new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s'),
        );
    }

    public function testSeRespaldaYVerificaAntesDeBorrar(): void
    {
        $vencidas = [$this->muestra('2026-07-01 06:15:00'), $this->muestra('2026-07-02 06:15:00')];
        $historico = new MemoriaHistorico($vencidas);
        $respaldo = new MemoriaRespaldo(2, true);

        $resultado = (new PurgarMuestrasVencidas($historico, $respaldo, 7))->execute(
            new DateTimeImmutable(self::AHORA),
            'writable/telemetria/respaldo.jsonl',
        );

        self::assertSame(
            ['leer', 'borrar'],
            $historico->operaciones,
            'El borrado es la última operación.',
        );
        self::assertSame(['escribir', 'verificar'], $respaldo->operaciones);
        self::assertSame(2, $resultado['borradas']);
        self::assertSame('writable/telemetria/respaldo.jsonl', $resultado['respaldo']);
    }

    public function testUnRespaldoIncompletoFrenaLaPurgaYNoBorraNada(): void
    {
        $historico = new MemoriaHistorico([$this->muestra('2026-07-01 06:15:00')]);
        $respaldo = new MemoriaRespaldo(0, true);

        try {
            (new PurgarMuestrasVencidas($historico, $respaldo, 7))->execute(
                new DateTimeImmutable(self::AHORA),
                'writable/telemetria/respaldo.jsonl',
            );

            self::fail('Un respaldo incompleto no puede pasar.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('No se borró nada', $exception->getMessage());
        }

        self::assertSame(
            ['leer'],
            $historico->operaciones,
            'El histórico tiene que quedar intacto si el respaldo no se pudo hacer.',
        );
    }

    public function testNoPermitePurgarSinRutaDeRespaldoAunqueSoloHayaMuestras(): void
    {
        $historico = new MemoriaHistorico([$this->muestra('2026-07-01 06:15:00')]);

        try {
            (new PurgarMuestrasVencidas($historico, new MemoriaRespaldo(1, true), 7))
                ->execute(new DateTimeImmutable(self::AHORA));
            self::fail('La purga debe exigir respaldo explícito.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Falta la ruta de respaldo', $exception->getMessage());
            self::assertStringContainsString('No se borró nada', $exception->getMessage());
        }

        self::assertSame(['leer'], $historico->operaciones);
    }

    public function testUnRespaldoQueSeDeclaraCompletoPeroNoLoEstaFrenaLaPurga(): void
    {
        $historico = new MemoriaHistorico([$this->muestra('2026-07-01 06:15:00')]);
        $respaldo = new MemoriaRespaldo(1, false);

        $this->expectException(RuntimeException::class);

        try {
            (new PurgarMuestrasVencidas($historico, $respaldo, 7))->execute(
                new DateTimeImmutable(self::AHORA),
                'writable/telemetria/respaldo.jsonl',
            );
        } finally {
            self::assertSame(['leer'], $historico->operaciones);
        }
    }

    public function testSiSeBorraMenosDeLoAnunciadoSeFallaYSeAvisa(): void
    {
        $historico = new MemoriaHistorico(
            [$this->muestra('2026-07-01 06:15:00'), $this->muestra('2026-07-02 06:15:00')],
            1,
        );
        $respaldo = new MemoriaRespaldo(2, true);

        try {
            (new PurgarMuestrasVencidas($historico, $respaldo, 7))->execute(
                new DateTimeImmutable(self::AHORA),
                'writable/telemetria/respaldo.jsonl',
            );

            self::fail('Borrar menos de lo respaldado tiene que ser un error visible.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('2', $exception->getMessage());
            self::assertStringContainsString('1', $exception->getMessage());
            self::assertStringContainsString('respaldo', $exception->getMessage());
        }
    }

    public function testRespaldosDeMuestrasYBucketsSeVerificanAntesDeEliminarAmbos(): void
    {
        $historico = new MemoriaHistorico([$this->muestra('2026-07-01 06:15:00')]);
        $buckets = new MemoriaBucketsHistoricos([$this->bucket()]);
        $respaldoMuestras = new MemoriaRespaldo(1, true);
        $respaldoBuckets = new MemoriaRespaldoBuckets(1, true);

        $resultado = (new PurgarMuestrasVencidas(
            $historico,
            $respaldoMuestras,
            7,
            90,
            $buckets,
            $respaldoBuckets,
        ))->execute(new DateTimeImmutable(self::AHORA), 'writable/telemetria/muestras.jsonl');

        self::assertSame(['leer', 'borrar'], $historico->operaciones);
        self::assertSame(['leer', 'borrar'], $buckets->operaciones);
        self::assertSame(
            ['escribir:writable/telemetria/muestras.buckets.jsonl', 'verificar:writable/telemetria/muestras.buckets.jsonl'],
            $respaldoBuckets->operaciones,
        );
        self::assertSame(1, $resultado['bucketsVencidos']);
        self::assertSame(1, $resultado['bucketsBorrados']);
        self::assertSame('writable/telemetria/muestras.buckets.jsonl', $resultado['respaldoBuckets']);
    }

    public function testUnRespaldoDeBucketsIncompletoImpideBorrarMuestrasYBuckets(): void
    {
        $historico = new MemoriaHistorico([$this->muestra('2026-07-01 06:15:00')]);
        $buckets = new MemoriaBucketsHistoricos([$this->bucket()]);
        $respaldoMuestras = new MemoriaRespaldo(1, true);
        $respaldoBuckets = new MemoriaRespaldoBuckets(0, true);

        try {
            (new PurgarMuestrasVencidas(
                $historico,
                $respaldoMuestras,
                7,
                90,
                $buckets,
                $respaldoBuckets,
            ))->execute(new DateTimeImmutable(self::AHORA), 'writable/telemetria/muestras.jsonl');
            self::fail('El respaldo incompleto de buckets debe frenar ambas purgas.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('No se borró nada', $exception->getMessage());
        }

        self::assertSame(['leer'], $historico->operaciones);
        self::assertSame(['leer'], $buckets->operaciones);
    }

    private function bucket(): BucketHistorico
    {
        return new BucketHistorico([
            'empresa_id' => 7,
            'integracion_id' => 10,
            'equipo_id' => 42,
            'unidad_externa' => 'WIALON-42',
            'proveedor' => 'WIALON',
            'bucket_inicio_utc' => '2026-07-01 06:10:00',
            'bucket_fin_utc' => '2026-07-01 06:15:00',
            'segundos_ralenti' => 120,
        ]);
    }

    private function muestra(string $observada): MuestraEquipo
    {
        $instante = new DateTimeImmutable($observada);

        return new MuestraEquipo(
            7,
            10,
            42,
            'WIALON-42',
            'WIALON',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-33.0944, -68.88, 0.0, 216, 955.4, 25, $instante),
                487500,
                40625,
                false,
                false,
                24.51,
                660.48,
            ),
            new DateTimeImmutable($observada),
        );
    }
}
