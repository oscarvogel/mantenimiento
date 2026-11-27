<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use InvalidArgumentException;

/**
 * Resultado de evaluar si un chofer puede recibir mensajes de una unidad.
 *
 * Cuando está bloqueado lleva un código estable (para métricas y consultas) y
 * un texto humano (para el operador). El código no cambia sin migración de los
 * datos ya persistidos.
 */
final readonly class DriverNotificationEligibility
{
    public const CHOFER_SIN_ASIGNACION = 'CHOFER_SIN_ASIGNACION';
    public const ASIGNACION_NO_INICIADA = 'ASIGNACION_NO_INICIADA';
    public const ASIGNACION_CERRADA = 'ASIGNACION_CERRADA';
    public const EMPLEADO_INACTIVO = 'EMPLEADO_INACTIVO';
    public const EMPLEADO_ELIMINADO = 'EMPLEADO_ELIMINADO';
    public const EQUIPO_INACTIVO = 'EQUIPO_INACTIVO';
    public const EQUIPO_ELIMINADO = 'EQUIPO_ELIMINADO';
    public const TIPO_SIN_KILOMETRAJE = 'TIPO_SIN_KILOMETRAJE';
    public const EMPRESA_INACTIVA = 'EMPRESA_INACTIVA';
    public const EMPRESA_ELIMINADA = 'EMPRESA_ELIMINADA';
    public const WHATSAPP_EMPRESA_DESHABILITADO = 'WHATSAPP_EMPRESA_DESHABILITADO';

    private function __construct(
        private bool $eligible,
        private ?string $reasonCode,
        private ?string $reasonText,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, null, null);
    }

    public static function blocked(string $reasonCode, string $reasonText): self
    {
        $reasonCode = strtoupper(trim($reasonCode));
        $reasonText = trim(preg_replace('/\s+/u', ' ', $reasonText) ?? '');

        if ($reasonCode === '') {
            throw new InvalidArgumentException('El código de cancelación es obligatorio.');
        }
        if ($reasonText === '') {
            throw new InvalidArgumentException('El detalle de la cancelación es obligatorio.');
        }

        return new self(false, $reasonCode, $reasonText);
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }

    public function isBlocked(): bool
    {
        return ! $this->eligible;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function reasonText(): ?string
    {
        return $this->reasonText;
    }

    /** Texto listo para persistir en `ultimo_error`, con el código delante. */
    public function displayReason(): string
    {
        if ($this->eligible) {
            return '';
        }

        return $this->reasonCode . ': ' . $this->reasonText;
    }
}
