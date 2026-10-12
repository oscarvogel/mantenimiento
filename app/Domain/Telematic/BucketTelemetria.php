<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/** Resumen observado de una ventana UTC, con extremos y tiempo de ralentí conocido. */
final readonly class BucketTelemetria
{
    public function __construct(
        private DateTimeImmutable $inicio,
        private DateTimeImmutable $fin,
        private MensajeHistorico $primero,
        private MensajeHistorico $ultimo,
        private ?int $segundosRalenti,
        /** @var list<array<string,mixed>> */
        private array $mensajesFuente = [],
    ) {
        if ($fin <= $inicio) {
            throw new InvalidArgumentException('El fin del bucket debe ser posterior a su inicio.');
        }

        if ($primero->observadaEn() < $inicio || $primero->observadaEn() >= $fin) {
            throw new InvalidArgumentException('El primer mensaje debe pertenecer al bucket.');
        }

        if ($ultimo->observadaEn() < $primero->observadaEn() || $ultimo->observadaEn() >= $fin) {
            throw new InvalidArgumentException('El último mensaje debe pertenecer al bucket y no preceder al primero.');
        }

        if ($segundosRalenti !== null && ($segundosRalenti < 0 || $segundosRalenti > ($fin->getTimestamp() - $inicio->getTimestamp()))) {
            throw new InvalidArgumentException('Los segundos de ralentí deben estar dentro de la duración del bucket.');
        }
    }

    public function inicio(): DateTimeImmutable
    {
        return $this->inicio;
    }

    public function fin(): DateTimeImmutable
    {
        return $this->fin;
    }

    public function primero(): MensajeHistorico
    {
        return $this->primero;
    }

    public function ultimo(): MensajeHistorico
    {
        return $this->ultimo;
    }

    public function segundosRalenti(): ?int
    {
        return $this->segundosRalenti;
    }

    /** @return list<array<string,mixed>> */
    public function mensajesFuente(): array
    {
        return $this->mensajesFuente;
    }
}
