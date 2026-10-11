<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\ConsumoConfigStore;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Domain\Telematic\Consumo\PoliticaConsumo;
use App\Domain\Telematic\Consumo\ViajeConsumido;
use DateTimeImmutable;
use DomainException;

/**
 * Datos de la pantalla de consumo por viaje.
 *
 * Es una lectura. No escribe, no refresca telemetría y no depende del reloj
 * salvo para marcar hasta dónde llega el período.
 *
 * El scoping por empresa **y** por sucursal sale del tablero de flota, que ya
 * resuelve qué equipos puede ver un responsable de planta. Reutilizarlo evita
 * tener dos lugares decidiendo lo mismo.
 *
 * La ventana se pide en días hacia atrás, no como dos fechas libres. Sobre una
 * tabla append-only un rango libre obliga a paginar y a no poder comparar, y lo
 * que interesa acá es cómo viene la semana, no el día que uno tenga en mente.
 */
final readonly class GetReporteConsumoFlota
{
    private const DIAS_MAXIMO = 90;

    /** Banda de consumo de la flota, del plan de integración. */
    private const BANDA_MINIMA = 33.0;
    private const BANDA_MAXIMA = 41.0;

    public function __construct(
        private FleetTelemetryBoardReader $flota,
        private CalcularConsumoPorEquipo $calculo,
        private ConsumoConfigStore $config,
        private NotificationClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, ?int $equipmentId = null, int $dias = 7): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar el consumo de esta flota.');
        }

        if ($dias <= 0 || $dias > self::DIAS_MAXIMO) {
            throw new DomainException('El período tiene que estar entre 1 y ' . self::DIAS_MAXIMO . ' días.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));

        $flota = $branchIds === []
            ? ['units' => [], 'branches' => []]
            : $this->flota->read($actor->companyId(), $branchIds);

        $elegido = $this->elegirEquipo($flota['units'], $equipmentId);

        if ($elegido === null) {
            return $this->vacio($actor->companyId(), $dias, $flota);
        }

        $hasta = $this->clock->now();
        $desde = $hasta->sub(new \DateInterval('P' . $dias . 'D'));

        $reporte = $this->calculo->execute(
            $actor->companyId(),
            (int) $elegido['equipmentId'],
            $desde,
            $hasta,
            PoliticaConsumo::desdeConfig($this->config->obtener($actor->companyId())),
        );

        return [
            'empresaId' => $actor->companyId(),
            'equipo' => [
                'id' => (int) $elegido['equipmentId'],
                'plate' => $elegido['plate'] ?? $elegido['code'] ?? '',
                'branchName' => $elegido['branchName'] ?? '',
            ],
            'equipos' => $this->opciones($flota['units']),
            'branches' => $flota['branches'],
            'window' => [
                'dias' => $dias,
                'desde' => $desde->format('Y-m-d H:i'),
                'hasta' => $hasta->format('Y-m-d H:i'),
            ],
            'bandaFlota' => ['minima' => self::BANDA_MINIMA, 'maxima' => self::BANDA_MAXIMA],
            'reporte' => [
                'sinCalibrar' => $reporte->sinCalibrar(),
                'muestrasLeidas' => $reporte->muestrasLeidas(),
                'cobertura' => [
                    'porcentaje' => (int) ($reporte->cobertura()->cobertura() * 100),
                    'suficiente' => $reporte->cobertura()->suficiente(),
                    'motivo' => $reporte->cobertura()->motivoInsuficiente(),
                    'minutosCubiertos' => $reporte->cobertura()->minutosCubiertos(),
                    'minutosEsperados' => $reporte->cobertura()->minutosEsperados(),
                ],
                'umbrales' => $reporte->umbrales(),
                'totales' => [
                    'kilometraje' => $reporte->kilometrajeTotal(),
                    'litros' => round($reporte->litrosTotales(), 2),
                    'viajes' => count($reporte->viajes()),
                    'conPorHora' => $reporte->viajesConHora(),
                    'sinConsumo' => $reporte->viajesSinConsumo(),
                ],
                'distribucion' => [
                    'mediana' => $reporte->distribucionCombustible()->medianaAbsoluta(),
                    'maximo' => $reporte->distribucionCombustible()->maximoAbsoluto(),
                    'intervalosConLectura' => $reporte->distribucionCombustible()->intervalosConLectura(),
                ],
                'viajes' => array_map($this->viaje(...), $reporte->viajes()),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function viaje(ViajeConsumido $viaje): array
    {
        $datos = $viaje->indicadores();
        $trayecto = $viaje->viaje();
        $consumo = $datos->litrosPor100Km();
        $porHora = $datos->litrosPorHoraMotor();

        return [
            'inicio' => $viaje->inicio(),
            'fin' => $viaje->fin(),
            'duracionMinutos' => $trayecto->duracionMinutos(),
            'minutosUtilizables' => $trayecto->minutosUtilizables(),
            'coberturaPeriodo' => $trayecto->duracionMinutos() > 0
                ? (int) (($trayecto->minutosUtilizables() / $trayecto->duracionMinutos()) * 100)
                : 0,
            'kilometraje' => $trayecto->kilometraje(),
            'litros' => round($trayecto->litrosConsumidos(), 2),
            'litrosCargados' => round($trayecto->litrosCargados(), 2),
            'litrosPor100Km' => $consumo === null ? null : round($consumo, 1),
            'litrosPorHoraMotor' => $porHora === null ? null : round($porHora, 1),
            'motivoSinPor100Km' => $datos->motivoSinPor100Km(),
            'motivoSinPorHora' => $datos->motivoSinPorHora(),
            'huboCarga' => $trayecto->huboCarga(),
            'reconstruible' => $trayecto->reconstruible(),
            'motivoNoReconstruible' => $trayecto->motivoNoReconstruible(),
            'fueraDeBanda' => $consumo !== null
                && ($consumo < self::BANDA_MINIMA || $consumo > self::BANDA_MAXIMA),
        ];
    }

    /**
     * @param  list<array<string, mixed>> $units
     * @return array<string, mixed>|null
     */
    private function elegirEquipo(array $units, ?int $equipmentId): ?array
    {
        if ($equipmentId !== null) {
            foreach ($units as $unit) {
                if ((int) $unit['equipmentId'] === $equipmentId) {
                    return $unit;
                }
            }

            return null;
        }

        return $units[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>> $units
     * @return list<array<string, mixed>>
     */
    private function opciones(array $units): array
    {
        $opciones = array_map(
            static fn (array $unit): array => [
                'id' => (int) $unit['equipmentId'],
                'plate' => $unit['plate'] ?? $unit['code'] ?? '',
                'branchName' => $unit['branchName'] ?? '',
            ],
            $units,
        );

        usort($opciones, static fn (array $a, array $b): int => strcmp($a['plate'], $b['plate']));

        return $opciones;
    }

    /**
     * @param  array<string, mixed> $flota
     * @return array<string, mixed>
     */
    private function vacio(?int $companyId, int $dias, array $flota = []): array
    {
        $hasta = $this->clock->now();

        return [
            'empresaId' => $companyId,
            'equipo' => null,
            'equipos' => $this->opciones($flota['units'] ?? []),
            'branches' => $flota['branches'] ?? [],
            'window' => [
                'dias' => $dias,
                'desde' => $hasta->sub(new \DateInterval('P' . $dias . 'D'))->format('Y-m-d H:i'),
                'hasta' => $hasta->format('Y-m-d H:i'),
            ],
            'bandaFlota' => ['minima' => self::BANDA_MINIMA, 'maxima' => self::BANDA_MAXIMA],
            'reporte' => null,
        ];
    }
}