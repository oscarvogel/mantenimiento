<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Uso\CorteIntegracion;
use App\Domain\Telematic\Uso\DetectorCortesIntegracion;
use App\Domain\Telematic\Uso\ExcesoVelocidad;
use App\Domain\Telematic\Uso\PoliticaUso;
use App\Domain\Telematic\Uso\UsoEquipo;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Uso de la flota, excesos de velocidad y estado de la telemetría en un período.
 *
 * Es una lectura gerencial. El scoping por empresa y sucursal sale del tablero
 * de flota (`FleetTelemetryBoardReader`), que ya decide qué equipos ve cada
 * usuario; reutilizarlo evita dos lugares decidiendo lo mismo.
 *
 * El período se limita a 31 días: la serie se lee unidad por unidad y, con el
 * histórico de cinco minutos, un mes de una flota chica ya son cientos de miles
 * de muestras. El hosting tiene 512 MB y 60 s por pedido.
 *
 * Un camión sin datos durante un corte de su integración no se informa como
 * "sin reportar": el problema no es el camión.
 */
final readonly class GetUsoFlota
{
    public const PERMISO = 'gerencial.flota.ver';
    private const DIAS_MAXIMO = 31;
    private const EXCESOS_MAXIMO = 50;

    public function __construct(
        private FleetTelemetryBoardReader $flota,
        private SerieMuestrasReader $muestras,
        private NotificationClock $clock,
        private PoliticaUso $politica = new PoliticaUso(),
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, int $dias = 7, ?int $sucursalId = null): array
    {
        $empresaId = $actor->companyId();
        if ($actor->isSuperAdmin() || $empresaId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar el uso de la flota.');
        }

        if ($dias <= 0 || $dias > self::DIAS_MAXIMO) {
            throw new DomainException('El período tiene que estar entre 1 y ' . self::DIAS_MAXIMO . ' días.');
        }

        $sucursales = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        if ($sucursalId !== null) {
            if (! $actor->canAccessBranch($empresaId, $sucursalId)) {
                throw new DomainException('No tenés acceso a esa sucursal.');
            }
            $sucursales = [$sucursalId];
        }

        $ahora = $this->clock->now();
        $zona = $ahora->getTimezone();
        $desde = $ahora->sub(new DateInterval('P' . $dias . 'D'));
        $flota = $sucursales === [] ? ['units' => [], 'branches' => []] : $this->flota->read($empresaId, $sucursales);

        $usos = [];
        $observaciones = [];
        $integraciones = [];
        foreach ($flota['units'] as $unidad) {
            $equipoId = (int) $unidad['equipmentId'];
            $principal = $this->fuentePrincipal($unidad['sources'] ?? []);
            if ($principal !== null) {
                $integraciones[(int) $principal['integrationId']] ??= [
                    'nombre' => (string) $principal['integrationName'],
                    'proveedor' => (string) $principal['provider'],
                ];
            }

            $serie = $this->muestras->serie($empresaId, $equipoId, $desde, $ahora);
            foreach ($serie as $muestra) {
                // Solo instantes: guardar las muestras de toda la flota a la vez
                // no entra en la memoria del hosting.
                $observaciones[$muestra->integracionId()][$equipoId][] = $muestra->observadaEn()->getTimestamp();
            }
            $usos[$equipoId] = [$unidad, $principal, UsoEquipo::medir($serie, $desde, $ahora, $this->politica)];
            unset($serie);
        }

        $detector = new DetectorCortesIntegracion($this->politica);
        $cortes = [];
        foreach ($observaciones as $integracionId => $porUnidad) {
            foreach ($detector->detectar($porUnidad, $ahora) as $corte) {
                $cortes[] = ['integracionId' => $integracionId, 'corte' => $corte];
            }
        }

        $abiertas = [];
        foreach ($cortes as $c) {
            if ($c['corte']->sigueAbierto) {
                $abiertas[$c['integracionId']] = true;
            }
        }

        $filas = [];
        $excesos = [];
        foreach ($usos as $equipoId => [$unidad, $principal, $uso]) {
            $integracionId = $principal === null ? null : (int) $principal['integrationId'];
            $filas[] = $this->fila($unidad, $principal, $uso, $ahora, $zona, $integracionId !== null && isset($abiertas[$integracionId]));
            foreach ($uso->excesos() as $exceso) {
                $excesos[] = $this->exceso($unidad, $exceso, $zona);
            }
        }

        usort($excesos, static fn (array $a, array $b): int => $b['velocidadMaximaKmh'] <=> $a['velocidadMaximaKmh']);

        return [
            'empresaId' => $empresaId,
            'periodo' => ['dias' => $dias, 'desde' => $desde->format('Y-m-d H:i'), 'hasta' => $ahora->format('Y-m-d H:i')],
            'umbrales' => [
                'velocidadExcesoKmh' => $this->politica->velocidadExcesoKmh,
                'sinReportarHoras' => intdiv($this->politica->sinReportarMinutos, 60),
                'corteMinimoHoras' => intdiv($this->politica->corteMinimoMinutos, 60),
            ],
            'totales' => $this->totales($filas, $excesos, $cortes),
            'unidades' => $filas,
            'excesos' => array_slice($excesos, 0, self::EXCESOS_MAXIMO),
            'cortes' => array_map(fn (array $c): array => $this->corte($c['corte'], $integraciones[$c['integracionId']] ?? null, $zona), $cortes),
            'branches' => $flota['branches'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $fuentes
     *
     * @return array<string,mixed>|null
     */
    private function fuentePrincipal(array $fuentes): ?array
    {
        foreach ($fuentes as $fuente) {
            if (strtoupper((string) ($fuente['role'] ?? '')) === 'PRINCIPAL') {
                return $fuente;
            }
        }

        return $fuentes[0] ?? null;
    }

    /**
     * @param array<string,mixed>      $unidad
     * @param array<string,mixed>|null $principal
     *
     * @return array<string,mixed>
     */
    private function fila(array $unidad, ?array $principal, UsoEquipo $uso, DateTimeImmutable $ahora, DateTimeZone $zona, bool $integracionCortada): array
    {
        $sinReportar = $uso->minutosSinReportar($ahora);
        $estado = match (true) {
            $uso->sinDatos() => $integracionCortada ? 'CORTE_INTEGRACION' : 'SIN_DATOS',
            $sinReportar !== null && $sinReportar >= $this->politica->sinReportarMinutos => $integracionCortada ? 'CORTE_INTEGRACION' : 'SIN_REPORTAR',
            default => 'OK',
        };

        return [
            'equipoId' => (int) $unidad['equipmentId'],
            'patente' => $unidad['plate'] ?? $unidad['code'] ?? '',
            'sucursalId' => (int) ($unidad['branchId'] ?? 0),
            'sucursal' => (string) ($unidad['branchName'] ?? ''),
            'proveedor' => $principal['provider'] ?? null,
            'estadoSenal' => $estado,
            'ultimoDato' => $uso->ultimoDato()?->setTimezone($zona)->format('Y-m-d H:i'),
            'horasSinReportar' => $sinReportar === null ? null : round($sinReportar / 60, 1),
            'km' => $uso->km(),
            'kmPorDia' => $uso->kmPorDia() === null ? null : round($uso->kmPorDia()),
            'horasMotor' => $uso->horasMotor() === null ? null : round($uso->horasMotor(), 1),
            'porcentajeMotorDetenido' => $uso->porcentajeMotorDetenido() === null ? null : round($uso->porcentajeMotorDetenido(), 1),
            'porcentajeRalenti' => $uso->porcentajeRalenti() === null ? null : round($uso->porcentajeRalenti(), 1),
            'velocidadMaximaKmh' => $uso->velocidadMaximaKmh(),
            'excesos' => count($uso->excesos()),
            'detalleUrl' => $unidad['detailUrl'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $unidad
     *
     * @return array<string,mixed>
     */
    private function exceso(array $unidad, ExcesoVelocidad $exceso, DateTimeZone $zona): array
    {
        return [
            'equipoId' => (int) $unidad['equipmentId'],
            'patente' => $unidad['plate'] ?? $unidad['code'] ?? '',
            'inicio' => $exceso->inicio->setTimezone($zona)->format('Y-m-d H:i'),
            'fin' => $exceso->fin->setTimezone($zona)->format('Y-m-d H:i'),
            'velocidadMaximaKmh' => $exceso->velocidadMaximaKmh,
            'muestras' => $exceso->muestras,
            'latitud' => $exceso->latitud,
            'longitud' => $exceso->longitud,
        ];
    }

    /**
     * @param array{nombre:string, proveedor:string}|null $integracion
     *
     * @return array<string,mixed>
     */
    private function corte(CorteIntegracion $corte, ?array $integracion, DateTimeZone $zona): array
    {
        return [
            'integracion' => $integracion['nombre'] ?? null,
            'proveedor' => $integracion['proveedor'] ?? null,
            'desde' => $corte->desde->setTimezone($zona)->format('Y-m-d H:i'),
            'hasta' => $corte->hasta->setTimezone($zona)->format('Y-m-d H:i'),
            'horas' => round($corte->horas()),
            'unidadesAfectadas' => $corte->unidadesAfectadas,
            'unidadesTotales' => $corte->unidadesTotales,
            'sigueAbierto' => $corte->sigueAbierto,
        ];
    }

    /**
     * @param list<array<string,mixed>> $filas
     * @param list<array<string,mixed>> $excesos
     * @param list<array{integracionId:int, corte:CorteIntegracion}> $cortes
     *
     * @return array<string,mixed>
     */
    private function totales(array $filas, array $excesos, array $cortes): array
    {
        $km = array_filter(array_column($filas, 'km'), static fn ($v): bool => $v !== null);
        $horas = array_filter(array_column($filas, 'horasMotor'), static fn ($v): bool => $v !== null);
        $estados = array_count_values(array_column($filas, 'estadoSenal'));

        return [
            'unidades' => count($filas),
            'km' => $km === [] ? null : array_sum($km),
            'unidadesConKm' => count($km),
            'horasMotor' => $horas === [] ? null : round(array_sum($horas), 1),
            'unidadesConHorasMotor' => count($horas),
            'excesos' => count($excesos),
            'sinReportar' => $estados['SIN_REPORTAR'] ?? 0,
            'sinDatos' => $estados['SIN_DATOS'] ?? 0,
            'enCorte' => $estados['CORTE_INTEGRACION'] ?? 0,
            'cortes' => count($cortes),
            'cortesAbiertos' => count(array_filter($cortes, static fn (array $c): bool => $c['corte']->sigueAbierto)),
        ];
    }
}
