<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;

/**
 * Instantánea de un equipo en un instante dado, tal como la ve una fuente.
 *
 * Los conceptos son **nuestros**, no del proveedor. Que Wialon llame
 * "COMBUSTIBLE T1" a un sensor y Gestya lo llame "Depo 1" es un detalle del
 * adaptador: acá sólo hay litros. Lo que el proveedor no se sabe interpretar
 * no se inventa ni se esconde: entra en `sensoresAdicionales` con su etiqueta
 * original, para que la ficha pueda mostrarlo sin que el resto del sistema
 * tenga que saber qué significa.
 *
 * Y lo que el proveedor reporta pero es imposible se aparta en `anomalias`,
 * en vez de propagarse como dato.
 */
final readonly class InstantaneaEquipo
{
    private DateTimeImmutable $observadaEn;
    private ?Posicion $posicion;
    private ?int $kilometraje;
    private ?int $horasDecimales;
    private ?bool $motorEncendido;
    private ?bool $ralentiActivo;
    private ?float $voltaje;
    private ?float $combustibleLitros;

    /** @var list<MedidaAdicional> */
    private array $sensoresAdicionales;

    /** @var list<LecturaImposible> */
    private array $anomalias;

    /**
     * @param list<MedidaAdicional> $sensoresAdicionales
     */
    public function __construct(
        DateTimeImmutable $observadaEn,
        ?Posicion $posicion,
        ?int $kilometraje,
        ?int $horasDecimales,
        ?bool $motorEncendido,
        ?bool $ralentiActivo,
        ?float $voltaje,
        ?float $combustibleLitros,
        array $sensoresAdicionales = [],
    ) {
        // Un valor imposible no es un dato: es un síntoma. Se aparta para que
        // no contamine la base y queda registrado para que alguien vaya a
        // mirar ese sensor. Se vio en la flota real: tres unidades con
        // -348.201 l de combustible (entrada analítica desconectada) y una con
        // 0,00 V (sensor apagado).
        $anomalias = [];

        if ($kilometraje !== null && $kilometraje < 0) {
            $anomalias[] = new LecturaImposible('KILOMETRAJE', (float) $kilometraje, 'un odómetro no puede estar por debajo de cero.');
            $kilometraje = null;
        }

        if ($horasDecimales !== null && $horasDecimales < 0) {
            $anomalias[] = new LecturaImposible('HORAS', (float) $horasDecimales, 'un horómetro no puede ser negativo.');
            $horasDecimales = null;
        }

        if ($voltaje !== null && $voltaje <= 0.0) {
            $anomalias[] = new LecturaImposible('VOLTAJE', $voltaje, 'cero voltios significa sensor apagado, no una batería descargada.');
            $voltaje = null;
        }

        if ($combustibleLitros !== null && $combustibleLitros < 0.0) {
            $anomalias[] = new LecturaImposible('COMBUSTIBLE', $combustibleLitros, 'un tanque no puede tener litros negativos: la entrada analítica está desconectada.');
            $combustibleLitros = null;
        }

        $this->anomalias = $anomalias;
        $this->observadaEn = $observadaEn;
        $this->posicion = $posicion;
        $this->kilometraje = $kilometraje;
        $this->horasDecimales = $horasDecimales;
        $this->motorEncendido = $motorEncendido;
        $this->ralentiActivo = $ralentiActivo;
        $this->voltaje = $voltaje;
        $this->combustibleLitros = $combustibleLitros;
        $this->sensoresAdicionales = $sensoresAdicionales;
    }

    public function observadaEn(): DateTimeImmutable
    {
        return $this->observadaEn;
    }

    public function posicion(): ?Posicion
    {
        return $this->posicion;
    }

    public function kilometraje(): ?int
    {
        return $this->kilometraje;
    }

    /** Horas de motor en décimas, igual que el resto del sistema. */
    public function horasDecimales(): ?int
    {
        return $this->horasDecimales;
    }

    public function motorEncendido(): ?bool
    {
        return $this->motorEncendido;
    }

    public function ralentiActivo(): ?bool
    {
        return $this->ralentiActivo;
    }

    public function voltaje(): ?float
    {
        return $this->voltaje;
    }

    public function combustibleLitros(): ?float
    {
        return $this->combustibleLitros;
    }

    /** @return list<MedidaAdicional> */
    public function sensoresAdicionales(): array
    {
        return $this->sensoresAdicionales;
    }

    /**
     * Lecturas que el proveedor reportó y el dominio no puede aceptar.
     * Es la entrada de la alerta de telemetría anómala.
     *
     * @return list<LecturaImposible>
     */
    public function anomalias(): array
    {
        return $this->anomalias;
    }

    public function tieneAnomalias(): bool
    {
        return $this->anomalias !== [];
    }

    public function antiguedadMinutos(DateTimeImmutable $ahora): int
    {
        return intdiv($ahora->getTimestamp() - $this->observadaEn->getTimestamp(), 60);
    }
}