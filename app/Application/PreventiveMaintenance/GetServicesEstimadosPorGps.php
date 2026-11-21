<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\LecturaKmVigenteReader;
use App\Application\PreventiveMaintenance\Port\OdometroGpsReader;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\Gps\EstimacionKmGps;
use App\Domain\PreventiveMaintenance\Gps\LecturaKmVigente;
use App\Domain\PreventiveMaintenance\Gps\PoliticaEstimacionGps;
use App\Domain\PreventiveMaintenance\UsoActual;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Próximo service de cada plan por km, estimado con los km que sumó el GPS
 * desde la última lectura del chofer.
 *
 * Los choferes cargan el odómetro cada 4 a 11 días, y en ese lapso un camión
 * hace miles de km: con solo la lectura, el service aparece más lejos de lo que
 * está. Esta lectura gerencial lo anticipa.
 *
 * **Es de solo lectura.** No graba lecturas, no mueve el odómetro del equipo,
 * no cambia el estado real del plan ni genera avisos: muestra el estado real y
 * el estimado lado a lado. La regla de vencimiento es la misma
 * (`EvaluadorVencimiento`); lo único que cambia es el km que recibe.
 *
 * La lectura vigente llega de Medición y el odómetro del GPS de Telemetría, por
 * dos contratos. Preventivo no lee sus tablas.
 */
final readonly class GetServicesEstimadosPorGps
{
    public const PERMISO = 'gerencial.flota.ver';

    private const RANGO = [
        'SIN_DATOS' => -1,
        'AL_DIA' => 0,
        'PROXIMO' => 1,
        'VENCIDO' => 2,
    ];

    public function __construct(
        private PreventivePlanReadModel $planes,
        private LecturaKmVigenteReader $lecturas,
        private OdometroGpsReader $gps,
        private Clock $clock,
        private EvaluadorVencimiento $evaluador = new EvaluadorVencimiento(),
        private PoliticaEstimacionGps $politica = new PoliticaEstimacionGps(),
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, ?int $sucursalId = null): array
    {
        $empresaId = $actor->companyId();
        if ($actor->isSuperAdmin() || $empresaId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar los services estimados por GPS.');
        }

        $sucursales = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        if ($sucursalId !== null) {
            if (! $actor->canAccessBranch($empresaId, $sucursalId)) {
                throw new DomainException('No tenés acceso a esa sucursal.');
            }
            $sucursales = [$sucursalId];
        }

        $ahora = $this->clock->now();
        $items = $sucursales === [] ? [] : array_values(array_filter(
            $this->planes->listActive($empresaId, $sucursales),
            static fn (PreventivePlanListItem $i): bool => $i->plan->usaKilometraje(),
        ));

        $equipoIds = array_values(array_unique(array_map(static fn (PreventivePlanListItem $i): int => $i->plan->equipoId(), $items)));
        $vigentes = $equipoIds === [] ? [] : $this->lecturas->vigentes($empresaId, $equipoIds);

        $estimaciones = [];
        foreach ($equipoIds as $equipoId) {
            $estimaciones[$equipoId] = $this->estimar($empresaId, $equipoId, $vigentes[$equipoId] ?? null, $ahora);
        }

        $filas = array_map(
            fn (PreventivePlanListItem $item): array => $this->fila($item, $estimaciones[$item->plan->equipoId()], $ahora),
            $items,
        );
        usort($filas, self::porUrgencia(...));

        return [
            'empresaId' => $empresaId,
            'calculadoEn' => $ahora->format('Y-m-d H:i'),
            'totales' => $this->totales($filas),
            'planes' => $filas,
        ];
    }

    private function estimar(int $empresaId, int $equipoId, ?LecturaKmVigente $lectura, DateTimeImmutable $ahora): EstimacionKmGps
    {
        if ($lectura === null) {
            return EstimacionKmGps::estimar(null, [], $ahora, $this->politica);
        }

        $tolerancia = $this->politica->toleranciaAnclaMinutos;
        $desde = max(
            $lectura->fecha->modify('-' . $tolerancia . ' minutes'),
            $ahora->modify('-' . $this->politica->antiguedadMaximaLecturaDias . ' days')->modify('-' . $tolerancia . ' minutes'),
        );

        return EstimacionKmGps::estimar(
            $lectura,
            $this->gps->odometros($empresaId, $equipoId, $desde, $ahora),
            $ahora,
            $this->politica,
        );
    }

    /** @return array<string, mixed> */
    private function fila(PreventivePlanListItem $item, EstimacionKmGps $estimacion, DateTimeImmutable $ahora): array
    {
        $plan = $item->plan;
        $zona = $ahora->getTimezone();
        $real = $this->evaluador->evaluar($plan, $item->currentUsage, $ahora);

        $estimado = null;
        if ($estimacion->esEstimable()) {
            $evaluacion = $this->evaluador->evaluar(
                $plan,
                new UsoActual($estimacion->kmEstimado(), $item->currentUsage->horasDecimas()),
                $ahora,
            );
            $estimado = [
                'km' => $estimacion->kmEstimado(),
                'kmRestantes' => self::restantes($plan->proximoKm(), $estimacion->kmEstimado()),
                'estado' => $evaluacion->estado()->value,
                'criterios' => $evaluacion->criteriosDisparadores(),
            ];
        }

        $lectura = $estimacion->lectura();

        return [
            'planId' => (int) $plan->id(),
            'equipoId' => $plan->equipoId(),
            'codigo' => $item->equipmentCode,
            'patente' => $item->equipmentPlate,
            'sucursalId' => $item->branchId,
            'sucursal' => $item->branchName,
            'servicio' => $item->serviceName,
            'proximoKm' => $plan->proximoKm(),
            'anticipacionKm' => $plan->anticipacionKm(),
            'proximaFecha' => $plan->proximaFecha()?->format('Y-m-d'),
            'real' => [
                'km' => $item->currentUsage->kilometraje(),
                'kmRestantes' => self::restantes($plan->proximoKm(), $item->currentUsage->kilometraje()),
                'estado' => $real->estado()->value,
            ],
            'lectura' => $lectura === null ? null : [
                'km' => $lectura->km,
                'fecha' => self::hora($lectura->fecha, $zona),
            ],
            'gps' => [
                'kmDesdeLectura' => $estimacion->kmGpsDesdeLectura(),
                'hasta' => $estimacion->ultimo() === null ? null : self::hora($estimacion->ultimo()->en, $zona),
            ],
            'estimado' => $estimado,
            'motivo' => $estimacion->motivo(),
            'seAdelanta' => $estimado !== null
                && self::RANGO[$estimado['estado']] > self::RANGO[$real->estado()->value],
        ];
    }

    /**
     * @param list<array<string, mixed>> $filas
     *
     * @return array<string, mixed>
     */
    private function totales(array $filas): array
    {
        $porEstado = array_fill_keys(array_map(static fn (EstadoPlan $e): string => $e->value, EstadoPlan::cases()), 0);
        $conEstimacion = 0;
        $seAdelantan = 0;
        foreach ($filas as $fila) {
            if ($fila['estimado'] === null) {
                continue;
            }
            $conEstimacion++;
            $porEstado[$fila['estimado']['estado']]++;
            $seAdelantan += $fila['seAdelanta'] ? 1 : 0;
        }

        return [
            'planes' => count($filas),
            'conEstimacion' => $conEstimacion,
            'sinEstimacion' => count($filas) - $conEstimacion,
            'porEstadoEstimado' => $porEstado,
            'seAdelantan' => $seAdelantan,
        ];
    }

    /** Más urgente primero: menos km restantes estimados; sin estimación, al final por km reales. */
    private static function porUrgencia(array $a, array $b): int
    {
        $clave = static fn (array $f): array => [
            $f['estimado'] === null ? 1 : 0,
            $f['estimado']['kmRestantes'] ?? $f['real']['kmRestantes'] ?? PHP_INT_MAX,
            $f['codigo'],
        ];

        return $clave($a) <=> $clave($b);
    }

    private static function restantes(?int $proximo, ?int $km): ?int
    {
        return $proximo === null || $km === null ? null : $proximo - $km;
    }

    private static function hora(DateTimeImmutable $fecha, DateTimeZone $zona): string
    {
        return $fecha->setTimezone($zona)->format('Y-m-d H:i');
    }
}
