<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * Línea de repuesto o consumible detectada en un documento de taller, lista para
 * persistirse como ítem estructurado de la OT (`orden_repuestos`).
 *
 * Reglas de dominio:
 * - La descripción es obligatoria y no puede superar los 255 caracteres de la
 *   columna; si el documento trae un texto mayor se conserva completo en
 *   `sourceText` y la línea queda pendiente de revisión humana.
 * - La cantidad es un DECIMAL positivo de tres decimales. Nunca se inventa un
 *   número: si el documento no permite determinar una cantidad válida (ausente,
 *   cero, negativa, no numérica o absurda) la línea queda `PENDIENTE_REVIEW` con
 *   cantidad 0 y un motivo legible para que una persona la complete.
 */
final readonly class ImportedPartLine
{
    public const STATUS_CONFIRMED = 'CONFIRMADO';
    public const STATUS_PENDING_REVIEW = 'PENDIENTE_REVIEW';

    /** Límite de la columna `orden_repuestos.descripcion`. */
    public const MAX_DESCRIPTION = 255;

    /** Por encima de un millón de unidades la cantidad se considera absurda. */
    public const MAX_QUANTITY = 1_000_000;

    private const PENDING_QUANTITY = '0.000';

    private function __construct(
        private string $description,
        private string $quantity,
        private string $status,
        private ?string $reviewReason,
        private ?string $unit,
        private ?string $sourceText,
    ) {}

    /**
     * Traduce una fila normalizada por el analizador de documentos a una línea
     * de repuesto. Devuelve `null` cuando la fila no tiene descripción utilizable
     * y por lo tanto no se puede registrar sin inventar información.
     *
     * @param array<string,mixed> $detected
     */
    public static function fromDetected(array $detected): ?self
    {
        $description = self::singleLine($detected['description'] ?? null);
        if ($description === '') {
            return null;
        }

        $status = self::STATUS_CONFIRMED;
        $reasons = [];
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION);
            $status = self::STATUS_PENDING_REVIEW;
            $reasons[] = 'La descripción del documento supera los 255 caracteres y quedó recortada.';
        }

        $quantity = self::normalizedQuantity($detected['quantity'] ?? null);
        if ($quantity === null) {
            $status = self::STATUS_PENDING_REVIEW;
            $quantity = self::PENDING_QUANTITY;
            $reasons[] = 'El documento no permite determinar una cantidad válida.';
        }

        $sourceText = self::singleLine($detected['source_text'] ?? null);

        return new self(
            $description,
            $quantity,
            $status,
            $reasons === [] ? null : implode(' ', $reasons),
            self::singleLine($detected['unit'] ?? null) ?: null,
            $sourceText === '' || $sourceText === $description ? null : $sourceText,
        );
    }

    /**
     * @param list<array<string,mixed>> $detected
     * @return list<self>
     */
    public static function listFromDetected(array $detected): array
    {
        $lines = [];
        foreach ($detected as $row) {
            if (! is_array($row)) {
                continue;
            }
            $line = self::fromDetected($row);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return string DECIMAL(12,3) con punto decimal y sin separadores. */
    public function quantity(): string
    {
        return $this->quantity;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function needsHumanReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function reviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    /** Texto original del documento cuando difiere de la descripción persistida. */
    public function sourceText(): ?string
    {
        return $this->sourceText;
    }

    /**
     * Resumen corto y legible de la línea, para las Observaciones de la OT.
     * No reemplaza al ítem estructurado: la OT impresa no lee `orden_repuestos`.
     */
    public function summary(): string
    {
        $text = $this->description;
        if ($this->needsHumanReview()) {
            return $text . ' (cantidad a revisar)';
        }

        return $text . ' x ' . self::humanQuantity($this->quantity) . ($this->unit === null ? '' : ' ' . $this->unit);
    }

    /**
     * Observaciones de la fila en `orden_repuestos`. Conserva el texto original
     * y la unidad declarada en el documento, porque la tabla no tiene columnas
     * para esos dos datos.
     */
    public function notes(): ?string
    {
        $notes = [];
        if ($this->reviewReason !== null) {
            $notes[] = 'PENDIENTE DE REVISIÓN HUMANA: ' . $this->reviewReason;
        }
        if ($this->unit !== null) {
            $notes[] = 'Unidad declarada en el documento: ' . $this->unit . '.';
        }
        if ($this->sourceText !== null) {
            $notes[] = 'Texto original del documento: ' . $this->sourceText;
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    private static function humanQuantity(string $quantity): string
    {
        $trimmed = rtrim(rtrim($quantity, '0'), '.');

        return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
    }

    private static function singleLine(mixed $value): string
    {
        // El espacio duro se convierte antes de colapsar: `trim()` trabaja por
        // bytes y no se puede usar con un charlist multibyte.
        $text = str_replace("\u{00A0}", ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Normaliza a DECIMAL(12,3). Devuelve `null` cuando la cantidad no es
     * utilizable, para que la línea quede pendiente de revisión en lugar de
     * persistir un número inventado.
     */
    private static function normalizedQuantity(mixed $raw): ?string
    {
        if ($raw === null || is_bool($raw) || is_array($raw)) {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
        } else {
            $text = str_replace([' ', "\u{00A0}"], '', trim((string) $raw));
            if ($text === '') {
                return null;
            }
            $text = str_replace(',', '.', $text);
            if (! is_numeric($text)) {
                return null;
            }
            $value = (float) $text;
        }

        if (! is_finite($value) || $value <= 0 || $value > self::MAX_QUANTITY) {
            return null;
        }

        $formatted = number_format($value, 3, '.', '');

        // Una cantidad positiva que redondea a cero no es utilizable como DECIMAL.
        return (float) $formatted <= 0.0 ? null : $formatted;
    }
}
