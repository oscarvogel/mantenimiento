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
        // Valores imposibles se normalizan a ausencia de dato, no se propagan.
        // Se vio en la flota real y conviene que el dominio lo selle: un
        // proveedor que devuelve litros negativos no está describiendo un
        // tanque vacío, está describiendo una entrada analógica desconectada.
        $this->observadaEn = $observadaEn;
        $this->posicion = $posicion;
        $this->kilometraje = $kilometraje !== null && $kilometraje >= 0 ? $kilometraje : null;
        $this->horasDecimales = $horasDecimales !== null && $horasDecimales >= 0 ? $horasDecimales : null;
        $this->motorEncendido = $motorEncendido;
        $this->ralentiActivo = $ralentiActivo;
        $this->voltaje = $voltaje !== null && $voltaje > 0.0 ? $voltaje : null;
        $this->combustibleLitros = $combustibleLitros !== null && $combustibleLitros >= 0.0 ? $combustibleLitros : null;
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

    public function antiguedadMinutos(DateTimeImmutable $ahora): int
    {
        return intdiv($ahora->getTimestamp() - $this->observadaEn->getTimestamp(), 60);
    }
}