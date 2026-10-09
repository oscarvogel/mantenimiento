<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Telematic\DiagnoseSilentUnits;
use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * La alerta de sensor inválido. Fija que convive con las otras dos y que no
 * duplica: la misma falla con el mismo valor no puede generar dos avisos.
 */
final class DiagnoseSilentUnitsAnomaliaTest extends TestCase
{
    private const AHORA = '2026-10-09 12:00:00';

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private function instantanea(float $combustible, float $voltaje = 25.0, string $observada = '2026-10-09 11:30:00'): InstantaneaEquipo
    {
        $momento = new DateTimeImmutable($observada);

        return new InstantaneaEquipo(
            $momento,
            new Posicion(-33.09, -68.88, 0.0, null, null, null, $momento),
            487500,
            40625,
            false,
            false,
            $voltaje,
            $combustible,
        );
    }

    private function diagnostico(InstantaneaEquipo $instantanea): DiagnoseSilentUnits
    {
        $fuente = new FuenteSenal(
            '1',
            'wialon',
            'Wialon TSA',
            '28396292',
            'PRINCIPAL',
            new EstadoSenal('28396292', $instantanea),
        );

        $cobertura = new CoberturaEquipo(4, 4, 34, 'AF081MJ', [$fuente]);

        $catalogo = new class([$cobertura]) implements \App\Application\Telematic\Port\EquipmentTelemetryCatalog {
            /** @param list<CoberturaEquipo> $coberturas */
            public function __construct(private readonly array $coberturas)
            {
            }

            public function coveredEquipmentFor(int $companyId): array
            {
                return $this->coberturas;
            }
        };

        $integraciones = new class implements \App\Application\Telematic\Port\TelemetryIntegrationCatalog {
            public function activeFor(int $companyId): array
            {
                return [new \App\Application\Telematic\IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')];
            }
        };

        $estados = ['28396292' => new EstadoSenal('28396292', $instantanea)];

        $registro = new class($estados) implements \App\Application\Telematic\Port\FleetTelemetryGatewayRegistry {
            public function __construct(private readonly array $estados)
            {
            }

            public function supports(string $provider): bool
            {
                return true;
            }

            public function forProvider(string $provider): \App\Application\Telematic\Port\FleetTelemetryGateway
            {
                return new class($this->estados) implements \App\Application\Telematic\Port\FleetTelemetryGateway {
                    public function __construct(private readonly array $estados)
                    {
                    }

                    public function fetchFor(int $integrationId): array
                    {
                        return $this->estados;
                    }
                };
            }
        };

        // Una clase anónima no hereda las constantes de la clase externa, así
        // que el reloj lleva la fecha literal.
        $reloj = new class implements \App\Application\Notifications\Port\NotificationClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-09 12:00:00');
            }
        };

        return new DiagnoseSilentUnits($catalogo, $integraciones, $registro, $reloj, 4, 24);
    }

    public function testUnSensorImposibleGeneraLaAlertaDeAnomalia(): void
    {
        $eventos = $this->diagnostico($this->instantanea(-348201.39))->execute();

        self::assertCount(1, $eventos);
        self::assertSame(DiagnoseSilentUnits::TYPE_ANOMALA, $eventos[0]->type());
        self::assertStringContainsString('AF081MJ', $eventos[0]->title());
        self::assertStringContainsString('COMBUSTIBLE', $eventos[0]->summary());
        self::assertStringContainsString('348.201,39', $eventos[0]->summary());
        self::assertStringContainsString('Wialon TSA', $eventos[0]->summary());
    }

    public function testUnEquipoSanoNoGeneraNingunaAlerta(): void
    {
        self::assertSame([], $this->diagnostico($this->instantanea(652.46))->execute());
    }

    public function testUnaBateriaEnCeroTambienSeReporta(): void
    {
        $eventos = $this->diagnostico($this->instantanea(652.46, 0.0))->execute();

        self::assertCount(1, $eventos);
        self::assertStringContainsString('VOLTAJE', $eventos[0]->summary());
    }

    public function testLaAnomaliaNoSeConfundeConLaFaltaDeSenal(): void
    {
        // Sensor roto pero reportando: hay anomalía y NO hay hueco de monitoreo.
        $eventos = $this->diagnostico($this->instantanea(-348201.39))->execute();

        $tipos = array_map(static fn ($e): string => $e->type(), $eventos);
        self::assertSame([DiagnoseSilentUnits::TYPE_ANOMALA], $tipos);
        self::assertNotContains(DiagnoseSilentUnits::TYPE_SIN_TELEMETRIA, $tipos);
    }

    public function testLaClaveLogicaNoSeRepiteConLaMismaFalla(): void
    {
        $primera = $this->diagnostico($this->instantanea(-348201.39))->execute()[0]->logicalKey();
        $segunda = $this->diagnostico($this->instantanea(-348201.39))->execute()[0]->logicalKey();

        self::assertSame($primera, $segunda);
    }

    public function testLaClaveLogicaCambiaSiCambiaLaFalla(): void
    {
        $una = $this->diagnostico($this->instantanea(-348201.39))->execute()[0]->logicalKey();
        $otra = $this->diagnostico($this->instantanea(-1000.0))->execute()[0]->logicalKey();

        self::assertNotSame($una, $otra);
    }

    public function testDosSensoresRotosDanDosAlertasDistintas(): void
    {
        $soloCombustible = $this->diagnostico($this->instantanea(-348201.39))->execute();
        $losDos = $this->diagnostico($this->instantanea(-348201.39, 0.0))->execute();

        self::assertCount(2, $losDos, 'Dos sensores rotos son dos avisos, no uno.');

        $claves = array_map(static fn ($e): string => $e->logicalKey(), $losDos);
        self::assertCount(2, array_unique($claves), 'Cada sensor tiene su propia clave.');
        self::assertContains($soloCombustible[0]->logicalKey(), $claves);
    }
}