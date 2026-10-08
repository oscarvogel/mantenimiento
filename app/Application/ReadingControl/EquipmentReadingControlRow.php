<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

/**
 * Fila de la pantalla de control de lecturas.
 *
 * Es un DTO de salida de solo lectura. No contiene datos de reclamos, de
 * entregas de notificación ni de canales de mensajería: esta pantalla no
 * envía nada.
 *
 * `daysSinceLastReading` es `null` cuando el equipo nunca tuvo una lectura
 * vigente. La ausencia de dato se distingue de "hace muchos días" porque el
 * operador necesita ver ambos casos por separado.
 */
final readonly class EquipmentReadingControlRow
{
    public function __construct(
        public int $equipmentId,
        public string $equipmentCode,
        public ?string $equipmentPlate,
        public string $typeName,
        public int $branchId,
        public string $branchName,
        public bool $controlsKm,
        public ?int $driverEmployeeId,
        public string $driverName,
        public ?string $driverPhone,
        public ?int $lastKm,
        public ?string $lastReadingAt,
        public ?int $daysSinceLastReading,
        public ?string $equipmentUrl,
        public ?int $lastReadingId = null,
        public ?string $readingMethod = null,
        public ?int $aiDetectedKm = null,
        public ?float $aiConfidence = null,
        public ?string $evidenceUrl = null,
    ) {
    }

    public function hasDriver(): bool
    {
        return $this->driverEmployeeId !== null && $this->driverEmployeeId > 0;
    }

    public function hasValidPhone(): bool
    {
        return $this->driverPhone !== null && trim($this->driverPhone) !== '';
    }

    public function hasReading(): bool
    {
        return $this->lastReadingAt !== null || $this->lastKm !== null;
    }

    /**
     * Estado derivado para la interfaz.
     *
     * `SIN_LECTURA` es el peor caso y se muestra explícitamente, en lugar de
     * ocultarlo dentro de un número grande de días.
     */
    public function status(ReadingControlFilter $filter): string
    {
        if (! $this->hasReading()) {
            return 'SIN_LECTURA';
        }

        $days = $this->daysSinceLastReading ?? 0;

        if ($filter->matches($this->lastReadingDate())) {
            return match ($filter->key) {
                ReadingControlFilter::TODAY => 'HOY',
                ReadingControlFilter::GT_3, ReadingControlFilter::GT_7 => 'ANTIGUO',
                default => $days > 7 ? 'ANTIGUO' : ($days > 3 ? 'REVISAR' : 'AL_DIA'),
            };
        }

        return 'FUERA_DE_FILTRO';
    }

    /**
     * Indica si la fila amerita un reclamo por WhatsApp.
     *
     * Reutiliza el criterio existente de "Más de 3 días" (`GT_3`): el reclamo
     * solo corresponde cuando la última lectura es anterior al corte o cuando
     * nunca hubo lectura (`SIN_LECTURA`, que `GT_3` ya incluye). `HOY` y
     * `AL_DIA` no habilitan reclamo. Es la misma regla que aplica el caso de
     * uso `ClaimReadingReminder` antes de enviar, para no duplicar criterios.
     */
    public function needsClaim(\DateTimeImmutable $now): bool
    {
        return ReadingControlFilter::fromKey(ReadingControlFilter::GT_3, $now)
            ->matches($this->lastReadingDate());
    }

    public function lastReadingDate(): ?\DateTimeImmutable
    {
        if ($this->lastReadingAt === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($this->lastReadingAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
