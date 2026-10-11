<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\AgregadorBucketsTelemetria;
use App\Domain\Telematic\MensajeHistorico;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AgregadorBucketsTelemetriaTest extends TestCase
{
    public function testAgrupaEnBucketsUtcSemiabiertosYDejaFueraElBucketAbierto(): void
    {
        $mensajes = [
            $this->mensaje('2026-10-10 12:04:00', combustible: 90.0),
            $this->mensaje('2026-10-10 12:01:00', combustible: 100.0),
            // Exactamente en el fin pertenece al bucket siguiente y cierra el primero.
            $this->mensaje('2026-10-10 12:05:00', combustible: 80.0),
        ];

        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            $mensajes,
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $buckets);
        self::assertSame('2026-10-10 12:00:00', $buckets[0]->inicio()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 12:05:00', $buckets[0]->fin()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 12:01:00', $buckets[0]->primero()->observadaEn()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 12:04:00', $buckets[0]->ultimo()->observadaEn()->format('Y-m-d H:i:s'));
        self::assertSame(100.0, $buckets[0]->primero()->combustibleLitros());
        self::assertSame(90.0, $buckets[0]->ultimo()->combustibleLitros());
    }

    public function testAlineaElBucketEnUtcAunqueElMensajeLlegueConOtraZonaHoraria(): void
    {
        $mensaje = $this->mensaje('2026-10-10 09:02:00', zona: 'America/Argentina/Buenos_Aires');

        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            [$mensaje, $this->mensaje('2026-10-10 12:05:00')],
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $buckets);
        self::assertSame('2026-10-10 12:00:00', $buckets[0]->inicio()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $buckets[0]->inicio()->getTimezone()->getName());
    }

    public function testDesempataTimestampsRepetidosConservandoElPrimerMensajeRecibido(): void
    {
        $mensajes = [
            $this->mensaje('2026-10-10 12:01:00', combustible: 100.0),
            $this->mensaje('2026-10-10 12:01:00', combustible: 50.0),
            $this->mensaje('2026-10-10 12:05:00'),
        ];

        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            $mensajes,
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $buckets);
        self::assertSame(100.0, $buckets[0]->primero()->combustibleLitros());
        self::assertSame(100.0, $buckets[0]->ultimo()->combustibleLitros());
    }

    public function testLasMetricasAusentesEnUnExtremoSiguenNulas(): void
    {
        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            [
                $this->mensaje('2026-10-10 12:01:00', kilometraje: 1000),
                $this->mensaje('2026-10-10 12:04:00', combustible: 80.0),
                $this->mensaje('2026-10-10 12:05:00'),
            ],
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertNull($buckets[0]->primero()->combustibleLitros());
        self::assertNull($buckets[0]->ultimo()->kilometraje());
    }

    public function testReparteRalentíQueCruzaElLimiteSinDuplicarSegundos(): void
    {
        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            [
                $this->mensaje('2026-10-10 12:04:00', ralenti: true),
                $this->mensaje('2026-10-10 12:06:00', ralenti: false),
                $this->mensaje('2026-10-10 12:10:00', ralenti: false),
            ],
            new DateTimeImmutable('2026-10-10 12:10:00', new DateTimeZone('UTC')),
        );

        self::assertCount(2, $buckets);
        self::assertSame(60, $buckets[0]->segundosRalenti());
        self::assertSame(60, $buckets[1]->segundosRalenti());
        self::assertSame(120, $buckets[0]->segundosRalenti() + $buckets[1]->segundosRalenti());
    }

    public function testUnHuecoMayorATreintaMinutosDejaElRalentíComoDesconocido(): void
    {
        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            [
                $this->mensaje('2026-10-10 12:01:00', ralenti: true),
                $this->mensaje('2026-10-10 12:32:00', ralenti: false),
                $this->mensaje('2026-10-10 12:35:00'),
            ],
            new DateTimeImmutable('2026-10-10 12:35:00', new DateTimeZone('UTC')),
        );

        self::assertCount(2, $buckets);
        self::assertNull($buckets[0]->segundosRalenti());
        self::assertNull($buckets[1]->segundosRalenti());
    }

    public function testUnBucketConCoberturaValidaSinRalentíRegistraCeroSegundos(): void
    {
        $buckets = (new AgregadorBucketsTelemetria())->agrupar(
            [
                $this->mensaje('2026-10-10 12:01:00', ralenti: false),
                $this->mensaje('2026-10-10 12:05:00', ralenti: false),
            ],
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertSame(0, $buckets[0]->segundosRalenti());
    }

    private function mensaje(
        string $fecha,
        ?float $combustible = null,
        ?int $kilometraje = null,
        ?bool $ralenti = null,
        string $zona = 'UTC',
    ): MensajeHistorico {
        return new MensajeHistorico(
            new DateTimeImmutable($fecha, new DateTimeZone($zona)),
            $combustible,
            $kilometraje,
            null,
            null,
            $ralenti,
        );
    }
}
