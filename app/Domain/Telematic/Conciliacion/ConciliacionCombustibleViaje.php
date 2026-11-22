<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Compara lo que declaró el chofer en un viaje con lo que midió el tanque.
 *
 * La regla de fondo es la 17 de la especificación: si faltan los datos
 * mínimos, se informa `sin datos suficientes` y no se inventa un número. Acá
 * eso pesa doble, porque un número inventado señala a una persona. Por eso,
 * antes de cualquier diferencia, se comprueba que la telemetría haya visto el
 * viaje completo:
 *
 * - que haya muestras y que el sensor del tanque reporte en la mayoría;
 * - que no haya un hueco largo (en el hueco pudo haber una carga invisible);
 * - que haya un nivel al salir y otro al volver.
 *
 * Los litros declarados se comparan con las cargas detectadas en la ventana.
 * El consumo medido (nivel al salir − nivel al volver + cargas) y el L/100 km
 * medido se informan aparte: sirven para ver si el viaje tiene sentido, no
 * para el veredicto.
 */
final readonly class ConciliacionCombustibleViaje
{
    private const MOTIVO_EN_CURSO = 'Las cargas se declaran al volver; todavía no se compara.';

    /**
     * @param list<CargaDetectada> $cargasDetectadas
     */
    private function __construct(
        private ViajeDeclarado $viaje,
        private EstadoConciliacion $estado,
        private ?string $motivo,
        private DateTimeImmutable $desde,
        private DateTimeImmutable $hasta,
        private array $cargasDetectadas,
        private ?float $litrosDetectados,
        private ?float $nivelSalida,
        private ?float $nivelRegreso,
        private ?int $kmGps,
        private ?float $coberturaSensor,
        private ?int $huecoMaximoMinutos,
        private PoliticaConciliacion $politica,
    ) {
    }

    /**
     * @param list<MuestraEquipo> $muestras Serie del equipo del viaje, en orden
     *                                      ascendente, que cubra la ventana del
     *                                      viaje con el margen de la política.
     */
    public static function evaluar(
        ViajeDeclarado $viaje,
        array $muestras,
        DateTimeImmutable $ahora,
        PoliticaConciliacion $politica = new PoliticaConciliacion(),
        ?DetectorCargasCombustible $detector = null,
    ): self {
        $desde = $viaje->inicioVentana();
        $hasta = $viaje->finVentana($ahora);
        $detector ??= new DetectorCargasCombustible($politica);
        $margen = $politica->margenExtremosMinutos * 60;

        // Un viaje en curso se informa siempre como en curso, aunque además la
        // telemetría tenga un problema: el motivo principal de que no haya
        // veredicto es que el chofer declara las cargas al volver. El problema de
        // telemetría se agrega al motivo para que no se pierda.
        $sinDatos = static fn (string $motivo, ?float $cobertura = null, ?int $hueco = null): self => new self(
            $viaje,
            $viaje->enCurso() ? EstadoConciliacion::EnCurso : EstadoConciliacion::SinDatosSuficientes,
            $viaje->enCurso() ? self::MOTIVO_EN_CURSO . ' Además: ' . lcfirst($motivo) : $motivo,
            $desde,
            $hasta,
            [],
            null,
            null,
            null,
            null,
            $cobertura,
            $hueco,
            $politica,
        );

        if ($viaje->equipoId === null) {
            return $sinDatos('El viaje no tiene tractor asignado.');
        }

        $dentro = array_values(array_filter(
            $muestras,
            static fn (MuestraEquipo $m): bool => $m->observadaEn() >= $desde && $m->observadaEn() <= $hasta,
        ));

        if ($dentro === []) {
            return $sinDatos('No hay telemetría del camión durante el viaje.');
        }

        $conTanque = array_values(array_filter(
            $dentro,
            static fn (MuestraEquipo $m): bool => $m->combustibleLitros() !== null,
        ));
        $cobertura = count($conTanque) / count($dentro);

        if ($cobertura < $politica->coberturaSensorMinima) {
            return $sinDatos(sprintf(
                'El sensor del tanque solo reportó en el %d%% de las lecturas del viaje.',
                (int) round($cobertura * 100),
            ), $cobertura);
        }

        $hueco = self::medirHuecoMaximo($conTanque, $desde, $hasta);
        if ($hueco > $politica->huecoMaximoMinutos) {
            return $sinDatos(sprintf(
                'Hay un hueco de %d h sin lecturas del tanque: pudo haber una carga que no se vio.',
                intdiv($hueco, 60),
            ), $cobertura, $hueco);
        }

        $nivelSalida = self::nivelCercano($muestras, $desde->getTimestamp() - $margen, $desde->getTimestamp(), true);
        $nivelRegreso = $viaje->enCurso()
            ? self::nivelCercano($conTanque, $hasta->getTimestamp() - $margen, $hasta->getTimestamp(), false)
            : self::nivelCercano($muestras, $hasta->getTimestamp(), $hasta->getTimestamp() + $margen, false);

        $cargas = array_values(array_filter(
            $detector->detectar($muestras),
            static fn (CargaDetectada $c): bool => $c->fin() >= $desde && $c->fin() <= $hasta,
        ));
        $litros = array_sum(array_map(static fn (CargaDetectada $c): float => $c->litros(), $cargas));
        $kmGps = self::medirKmGps($dentro);

        if ($viaje->enCurso()) {
            return new self($viaje, EstadoConciliacion::EnCurso, self::MOTIVO_EN_CURSO, $desde, $hasta, $cargas, $litros, $nivelSalida, $nivelRegreso, $kmGps, $cobertura, $hueco, $politica);
        }

        if ($nivelSalida === null || $nivelRegreso === null) {
            return $sinDatos(
                $nivelSalida === null
                    ? 'No hay lectura del tanque al salir.'
                    : 'No hay lectura del tanque al volver.',
                $cobertura,
                $hueco,
            );
        }

        $estado = self::veredicto($viaje->litrosDeclarados, $litros, $politica->toleranciaPorcentaje);

        return new self($viaje, $estado, null, $desde, $hasta, $cargas, $litros, $nivelSalida, $nivelRegreso, $kmGps, $cobertura, $hueco, $politica);
    }

    public function viaje(): ViajeDeclarado
    {
        return $this->viaje;
    }

    public function estado(): EstadoConciliacion
    {
        return $this->estado;
    }

    public function motivo(): ?string
    {
        return $this->motivo;
    }

    public function desde(): DateTimeImmutable
    {
        return $this->desde;
    }

    public function hasta(): DateTimeImmutable
    {
        return $this->hasta;
    }

    /** @return list<CargaDetectada> */
    public function cargasDetectadas(): array
    {
        return $this->cargasDetectadas;
    }

    public function litrosDetectados(): ?float
    {
        return $this->litrosDetectados;
    }

    public function litrosDeclarados(): float
    {
        return $this->viaje->litrosDeclarados;
    }

    /** Declarado menos detectado. Solo existe con veredicto. */
    public function diferenciaLitros(): ?float
    {
        if (! $this->estado->esVeredicto() || $this->litrosDetectados === null) {
            return null;
        }

        return $this->viaje->litrosDeclarados - $this->litrosDetectados;
    }

    /** Diferencia sobre lo detectado, en %. Sin cargas detectadas no hay base. */
    public function diferenciaPorcentaje(): ?float
    {
        $diferencia = $this->diferenciaLitros();
        if ($diferencia === null || $this->litrosDetectados === null || $this->litrosDetectados <= 0.0) {
            return null;
        }

        return $diferencia / $this->litrosDetectados * 100;
    }

    public function nivelSalida(): ?float
    {
        return $this->nivelSalida;
    }

    public function nivelRegreso(): ?float
    {
        return $this->nivelRegreso;
    }

    public function kmGps(): ?int
    {
        return $this->kmGps;
    }

    /** Nivel al salir − nivel al volver + cargas detectadas. */
    public function consumoMedido(): ?float
    {
        if ($this->nivelSalida === null || $this->nivelRegreso === null || $this->litrosDetectados === null) {
            return null;
        }

        $consumo = $this->nivelSalida - $this->nivelRegreso + $this->litrosDetectados;

        return $consumo > 0.0 ? $consumo : null;
    }

    public function litrosPor100KmMedido(): ?float
    {
        $consumo = $this->consumoMedido();
        if ($consumo === null || $this->kmGps === null || $this->kmGps < $this->politica->kmMinimosConsumo) {
            return null;
        }

        return $consumo / $this->kmGps * 100;
    }

    /**
     * L/100 km con las cargas declaradas y los km del viaje: el mismo cálculo
     * que muestra el módulo Viajes. No es consumo real (el tanque no vuelve
     * igual que salió), pero es la cifra con la que hoy se mira el viaje.
     */
    public function litrosPor100KmDeclarado(): ?float
    {
        $km = $this->viaje->kmDeclarados();
        if ($km === null || $this->viaje->litrosDeclarados <= 0.0) {
            return null;
        }

        return $this->viaje->litrosDeclarados / $km * 100;
    }

    public function coberturaSensor(): ?float
    {
        return $this->coberturaSensor;
    }

    public function huecoMaximoMinutos(): ?int
    {
        return $this->huecoMaximoMinutos;
    }

    private static function veredicto(float $declarados, float $detectados, float $tolerancia): EstadoConciliacion
    {
        if ($declarados <= 0.0 && $detectados <= 0.0) {
            return EstadoConciliacion::Conciliado;
        }

        if ($detectados <= 0.0) {
            return EstadoConciliacion::DeclaradoSupera;
        }

        $porcentaje = ($declarados - $detectados) / $detectados * 100;
        if ($porcentaje > $tolerancia) {
            return EstadoConciliacion::DeclaradoSupera;
        }

        if ($porcentaje < -$tolerancia) {
            return EstadoConciliacion::DeclaradoMenor;
        }

        return EstadoConciliacion::Conciliado;
    }

    /**
     * Hueco más largo entre lecturas del tanque, contando los extremos del
     * viaje: un viaje cuya primera lectura llega a las 10 horas de salir tiene
     * un hueco de 10 horas aunque después reporte cada 15 minutos.
     *
     * @param list<MuestraEquipo> $conTanque
     */
    private static function medirHuecoMaximo(array $conTanque, DateTimeImmutable $desde, DateTimeImmutable $hasta): int
    {
        $marcas = [$desde->getTimestamp()];
        foreach ($conTanque as $muestra) {
            $marcas[] = $muestra->observadaEn()->getTimestamp();
        }
        $marcas[] = $hasta->getTimestamp();

        $maximo = 0;
        for ($k = 1, $n = count($marcas); $k < $n; $k++) {
            $maximo = max($maximo, $marcas[$k] - $marcas[$k - 1]);
        }

        return intdiv($maximo, 60);
    }

    /**
     * Mediana de hasta tres lecturas del tanque en el tramo, las más cercanas
     * al borde del viaje.
     *
     * @param list<MuestraEquipo> $muestras
     */
    private static function nivelCercano(array $muestras, int $desde, int $hasta, bool $lasUltimas): ?float
    {
        $niveles = [];
        foreach ($muestras as $muestra) {
            $t = $muestra->observadaEn()->getTimestamp();
            if ($t >= $desde && $t <= $hasta && $muestra->combustibleLitros() !== null) {
                $niveles[] = (float) $muestra->combustibleLitros();
            }
        }

        if ($niveles === []) {
            return null;
        }

        $niveles = $lasUltimas ? array_slice($niveles, -3) : array_slice($niveles, 0, 3);
        sort($niveles);
        $n = count($niveles);
        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? $niveles[$medio] : ($niveles[$medio - 1] + $niveles[$medio]) / 2;
    }

    /** @param list<MuestraEquipo> $muestras */
    private static function medirKmGps(array $muestras): ?int
    {
        $odometros = array_values(array_filter(
            array_map(static fn (MuestraEquipo $m): ?int => $m->kilometraje(), $muestras),
            static fn (?int $km): bool => $km !== null,
        ));

        if (count($odometros) < 2) {
            return null;
        }

        $km = max($odometros) - min($odometros);

        return $km > 0 ? $km : null;
    }
}
