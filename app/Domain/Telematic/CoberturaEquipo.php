<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Cobertura de telemetría de un equipo: todas sus fuentes y qué se puede
 * afirmar a partir de ellas.
 *
 * Esta clase es el corazón del modelo multi-fuente. La pregunta correcta no
 * es "¿este proveedor reportando?", sino "¿alguna de las fuentes de este
 * equipo trajo una señal fresca?".
 *
 * De ahí salen dos hechos distintos que antes iban confundidos en uno:
 *
 * - El equipo **no está monitoreado** cuando tiene fuentes y ninguna reporta.
 * - Una fuente **está caída** cuando dejó de responder pero el equipo sigue
 *   cubierto por otra. Eso es un problema de integración, no de flota, y no
 *   puede esconderse: si no se avisa, el operador ve silencio y asume que
 *   todo anda bien.
 */
final readonly class CoberturaEquipo
{
    /**
     * @param list<FuenteSenal> $fuentes
     */
    public function __construct(
        private int $companyId,
        private ?int $branchId,
        private int $equipmentId,
        private string $code,
        private array $fuentes,
    ) {
        if ($companyId <= 0 || $equipmentId <= 0 || trim($code) === '') {
            throw new InvalidArgumentException('La cobertura requiere empresa, equipo y código válidos.');
        }
    }

    /** @return list<FuenteSenal> */
    public function fuentes(): array
    {
        return $this->fuentes;
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function equipmentId(): int
    {
        return $this->equipmentId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function estaMonitoreado(): bool
    {
        return $this->fuentes !== [];
    }

    /**
     * True cuando hay al menos una fuente con señal dentro del umbral.
     * Sin fuentes el equipo no está sin telemetría: no está enlazado, y eso es
     * un problema de configuración que se reporta por otro lado.
     */
    public function algunaReporta(DateTimeImmutable $ahora, int $umbralHoras): bool
    {
        if ($umbralHoras <= 0 || $this->fuentes === []) {
            return false;
        }

        foreach ($this->fuentes as $fuente) {
            if (! $fuente->estaEstancada($ahora, $umbralHoras)) {
                return true;
            }
        }

        return false;
    }

    /** El equipo tiene fuentes y ninguna trajo señal reciente. */
    public function estaSinMonitorear(DateTimeImmutable $ahora, int $umbralHoras): bool
    {
        if ($umbralHoras <= 0 || $this->fuentes === []) {
            return false;
        }

        return ! $this->algunaReporta($ahora, $umbralHoras);
    }

    /** Fuentes que dejaron de responder, cuando el equipo sigue cubierto por otra. */
    public function fuentesCaidas(DateTimeImmutable $ahora, int $umbralHoras): array
    {
        if ($umbralHoras <= 0) {
            return [];
        }

        $caidas = [];
        foreach ($this->fuentes as $fuente) {
            if ($fuente->estaEstancada($ahora, $umbralHoras)) {
                $caidas[] = $fuente;
            }
        }

        return $caidas;
    }

    /**
     * Antigüedad de la señal más reciente entre todas las fuentes. Es el dato
     * que hay que mostrar siempre junto a una posición: un punto en el mapa
     * sin su fecha manda a un chofer a un lugar donde el camión no está.
     */
    public function ultimaSenalEn(): ?DateTimeImmutable
    {
        $reciente = null;

        foreach ($this->fuentes as $fuente) {
            $senal = $fuente->reportaEn();
            if ($senal !== null && ($reciente === null || $senal > $reciente)) {
                $reciente = $senal;
            }
        }

        return $reciente;
    }

    /**
     * Ciclo para la clave lógica de la alerta "sin monitorear". Cambia sólo
     * cuando cambia la señal más reciente, así que repetir la corrida con el
     * mismo estado no duplica nada.
     */
    public function ciclo(): string
    {
        return $this->ultimaSenalEn()?->format('YmdHis') ?? 'sin_senal';
    }
}