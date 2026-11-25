<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

/**
 * Ciclo de vida del neumático: los 11 estados del sistema anterior, tal cual.
 *
 * Las transiciones salen del plan de ampliación (§5.1) y de su invariante 6:
 * un neumático no llega a BAJA DEFINITIVA sin pasar antes por RECAPADA,
 * VULCANIZADA o DESCARTE. El diagrama del plan dibuja además un salto de
 * EN REPARACION a BAJA DEFINITIVA que contradice esa invariante; se aplica la
 * invariante y el salto queda registrado como pregunta abierta en
 * `docs/BITACORA_2026-11-25_NEUMATICOS_DOMINIO.md`.
 */
enum NeumaticStatus: string
{
    case NEW = 'NUEVA';
    case HALF_USED = 'MEDIO_USO';
    case IN_REPAIR = 'EN_REPARACION';
    case REPAIRED = 'REPARADO';
    case RETREADED = 'RECAPADA';
    case VULCANIZED = 'VULCANIZADA';
    case DISCARDED = 'DESCARTE';
    case WRITTEN_OFF = 'BAJA_DEFINITIVA';
    case TRANSFERRED = 'TRANSFERIDA';
    case FOR_SALE = 'PARA_VENTA';
    case SOLD = 'VENDIDA';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    /**
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::NEW => [self::HALF_USED, self::FOR_SALE],
            self::HALF_USED => [self::IN_REPAIR],
            self::IN_REPAIR => [self::REPAIRED],
            self::REPAIRED => [self::VULCANIZED, self::RETREADED, self::DISCARDED, self::TRANSFERRED],
            self::RETREADED, self::VULCANIZED, self::DISCARDED => [self::WRITTEN_OFF],
            self::FOR_SALE => [self::SOLD],
            self::WRITTEN_OFF, self::TRANSFERRED, self::SOLD => [],
        };
    }

    /**
     * El neumático salió de la empresa o del servicio para siempre.
     */
    public function isFinal(): bool
    {
        return $this->nextStates() === [];
    }

    /**
     * Solo un neumático en condiciones de rodar puede estar colocado en un
     * equipo. Uno en reparación, descartado, a la venta o fuera de la empresa
     * no se monta.
     */
    public function canBeMounted(): bool
    {
        return in_array($this, [self::NEW, self::HALF_USED, self::REPAIRED, self::RETREADED, self::VULCANIZED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Nueva',
            self::HALF_USED => '1/2 uso',
            self::IN_REPAIR => 'En reparación',
            self::REPAIRED => 'Reparado',
            self::RETREADED => 'Recapada',
            self::VULCANIZED => 'Vulcanizada',
            self::DISCARDED => 'Descarte',
            self::WRITTEN_OFF => 'Baja definitiva',
            self::TRANSFERRED => 'Transferida',
            self::FOR_SALE => 'Para venta',
            self::SOLD => 'Vendida',
        };
    }
}
