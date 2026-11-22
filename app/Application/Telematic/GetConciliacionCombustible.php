<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Application\Telematic\Port\ViajesDeclaradosReader;
use App\Domain\Telematic\Conciliacion\ConciliacionCombustibleViaje;
use App\Domain\Telematic\Conciliacion\DetectorCargasCombustible;
use App\Domain\Telematic\Conciliacion\EstadoConciliacion;
use App\Domain\Telematic\Conciliacion\PoliticaConciliacion;
use DateInterval;
use DomainException;

/**
 * Compara, viaje por viaje, los litros que declaró el chofer con las cargas
 * que vio el sensor del tanque.
 *
 * Es una lectura gerencial: no escribe, no refresca telemetría y no marca a
 * nadie. El veredicto existe solo donde la telemetría vio el viaje completo;
 * el resto se informa como `sin datos suficientes` con su motivo, para que el
 * tablero no confunda un corte del GPS con un chofer que declaró de más.
 *
 * Los totales se calculan **solo con los viajes con veredicto**. Sumar los
 * litros declarados de un viaje sin telemetría contra cero litros detectados
 * inflaría la diferencia de toda la flota.
 */
final readonly class GetConciliacionCombustible
{
    public const PERMISO = 'gerencial.flota.ver';
    private const DIAS_MAXIMO = 90;

    public function __construct(
        private ViajesDeclaradosReader $viajes,
        private SerieMuestrasReader $muestras,
        private NotificationClock $clock,
        private PoliticaConciliacion $politica = new PoliticaConciliacion(),
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, int $dias = 30, ?int $sucursalId = null): array
    {
        $empresaId = $actor->companyId();
        if ($actor->isSuperAdmin() || $empresaId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar la conciliación de combustible.');
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
        $desde = $ahora->sub(new DateInterval('P' . $dias . 'D'));
        $viajes = $sucursales === [] ? [] : $this->viajes->viajesEntre($empresaId, $sucursales, $desde, $ahora);

        $detector = new DetectorCargasCombustible($this->politica);
        $antes = $this->politica->margenExtremosMinutos;
        $despues = $this->politica->margenExtremosMinutos
            + $this->politica->ventanaCargaMinutos
            + $this->politica->confirmacionHastaMinutos;

        $filas = [];
        $resultados = [];
        foreach ($viajes as $viaje) {
            $serie = [];
            if ($viaje->equipoId !== null) {
                $serie = $this->muestras->serie(
                    $empresaId,
                    $viaje->equipoId,
                    $viaje->inicioVentana()->modify('-' . $antes . ' minutes'),
                    $viaje->finVentana($ahora)->modify('+' . $despues . ' minutes'),
                );
            }

            $resultado = ConciliacionCombustibleViaje::evaluar($viaje, $serie, $ahora, $this->politica, $detector);
            $resultados[] = $resultado;
            $filas[] = $this->fila($resultado, $ahora->getTimezone());
        }

        return [
            'empresaId' => $empresaId,
            'periodo' => [
                'dias' => $dias,
                'desde' => $desde->format('Y-m-d H:i'),
                'hasta' => $ahora->format('Y-m-d H:i'),
            ],
            'tolerancia' => $this->politica->toleranciaPorcentaje,
            'totales' => $this->totales($resultados),
            'viajes' => $filas,
        ];
    }

    /**
     * Las horas salen todas en la zona del reloj del sistema. La serie mezcla
     * muestras en hora local con buckets en UTC (cada una conserva su zona), y
     * sin normalizar una misma carga podría mostrarse corrida tres horas.
     *
     * @return array<string, mixed>
     */
    private function fila(ConciliacionCombustibleViaje $r, \DateTimeZone $zona): array
    {
        $viaje = $r->viaje();
        $hora = static fn (\DateTimeImmutable $fecha): string => $fecha->setTimezone($zona)->format('Y-m-d H:i');

        return [
            'viajeId' => $viaje->viajeId,
            'numero' => $viaje->numero,
            'estadoViaje' => $viaje->estado,
            'sucursalId' => $viaje->sucursalId,
            'equipoId' => $viaje->equipoId,
            'patente' => $viaje->patente,
            'desde' => $hora($r->desde()),
            'hasta' => $hora($r->hasta()),
            'estado' => $r->estado()->value,
            'estadoEtiqueta' => $r->estado()->etiqueta(),
            'motivo' => $r->motivo(),
            'declarado' => [
                'litros' => round($viaje->litrosDeclarados, 1),
                'cargas' => $viaje->cargasDeclaradas,
                'km' => $viaje->kmDeclarados(),
                'litrosPor100Km' => self::redondear($r->litrosPor100KmDeclarado()),
            ],
            'medido' => [
                'litros' => self::redondear($r->litrosDetectados()),
                'cargas' => count($r->cargasDetectadas()),
                'km' => $r->kmGps(),
                'consumo' => self::redondear($r->consumoMedido()),
                'litrosPor100Km' => self::redondear($r->litrosPor100KmMedido()),
                'coberturaSensor' => $r->coberturaSensor() === null ? null : (int) round($r->coberturaSensor() * 100),
                // Lo medido se informa siempre que hay datos; si la telemetría no
                // vio el viaje completo, es parcial y no hay veredicto.
                'completo' => $r->medicionCompleta(),
                'detalleCargas' => array_map(static fn ($c): array => [
                    'inicio' => $hora($c->inicio()),
                    'fin' => $hora($c->fin()),
                    'litros' => round($c->litros(), 1),
                ], $r->cargasDetectadas()),
            ],
            'diferencia' => [
                'litros' => self::redondear($r->diferenciaLitros()),
                'porcentaje' => self::redondear($r->diferenciaPorcentaje()),
            ],
        ];
    }

    /**
     * @param list<ConciliacionCombustibleViaje> $resultados
     *
     * @return array<string, mixed>
     */
    private function totales(array $resultados): array
    {
        $porEstado = array_fill_keys(array_map(static fn (EstadoConciliacion $e): string => $e->value, EstadoConciliacion::cases()), 0);
        $declarados = 0.0;
        $detectados = 0.0;

        foreach ($resultados as $r) {
            $porEstado[$r->estado()->value]++;
            if ($r->estado()->esVeredicto()) {
                $declarados += $r->litrosDeclarados();
                $detectados += (float) $r->litrosDetectados();
            }
        }

        $conVeredicto = $porEstado[EstadoConciliacion::Conciliado->value]
            + $porEstado[EstadoConciliacion::DeclaradoSupera->value]
            + $porEstado[EstadoConciliacion::DeclaradoMenor->value];

        return [
            'viajes' => count($resultados),
            'conVeredicto' => $conVeredicto,
            'porEstado' => $porEstado,
            'litrosDeclarados' => $conVeredicto === 0 ? null : round($declarados, 1),
            'litrosDetectados' => $conVeredicto === 0 ? null : round($detectados, 1),
            'diferenciaLitros' => $conVeredicto === 0 ? null : round($declarados - $detectados, 1),
            'diferenciaPorcentaje' => $conVeredicto === 0 || $detectados <= 0.0
                ? null
                : round(($declarados - $detectados) / $detectados * 100, 1),
        ];
    }

    private static function redondear(?float $valor): ?float
    {
        return $valor === null ? null : round($valor, 1);
    }
}
