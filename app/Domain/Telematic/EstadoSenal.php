<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Estado de la señal de una unidad instrumentada.
 *
 * El estado es la instantánea completa cuando el proveedor la trae, y nada
 * cuando no. La fecha nunca se guarda suelta: sale de la instantánea, porque
 * una posición sin su hora es un punto en el mapa que miente.
 *
 * Una unidad que nunca emitió señal no se considera estancada acá. No emitir
 * nunca y dejar de emitir son fallos distintos: el primero suele ser un
 * vínculo mal configurado y corresponde al caso de uso decidirlo.
 */
final readonly class EstadoSenal
{
    public function __construct(
        private string $unidadExterna,
        private ?InstantaneaEquipo $instantanea,
    ) {
        if (trim($unidadExterna) === '') {
            throw new InvalidArgumentException('El estado de señal requiere la unidad externa.');
        }
    }

    public function unidadExterna(): string
    {
        return $this->unidadExterna;
    }

    public function instantanea(): ?InstantaneaEquipo
    {
        return $this->instantanea;
    }

    public function ultimaSenalEn(): ?DateTimeImmutable
    {
        return $this->instantanea?->observadaEn();
    }

    /** Minutos transcurridos desde la última señal. */
    public function minutosSinSenal(DateTimeImmutable $ahora): ?int
    {
        return $this->instantanea?->antiguedadMinutos($ahora);
    }

    /**
     * Una señal está estancada cuando su última emisión es anterior al umbral.
     * Un umbral de cero o negativo desactiva el criterio.
     */
    public function estaEstancada(DateTimeImmutable $ahora, int $umbralHoras): bool
    {
        if ($umbralHoras <= 0 || $this->instantanea === null) {
            return false;
        }

        return $this->instantanea->observadaEn() < $ahora->modify('-' . $umbralHoras . ' hours');
    }

    /**
     * Identificador del ciclo de silencio actual. Mientras la última señal no
     * cambie, el ciclo no cambia: eso es lo que hace idempotente la alerta.
     */
    public function ciclo(): string
    {
        return $this->instantanea?->observadaEn()->format('YmdHis') ?? 'sin_senal';
    }
}