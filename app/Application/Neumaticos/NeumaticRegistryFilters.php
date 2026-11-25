<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Domain\Neumaticos\NeumaticStatus;

/**
 * Filtros del padrón, leídos de la query. Un valor inválido se ignora en vez
 * de romper la pantalla: el operador ve el padrón sin ese filtro.
 */
final readonly class NeumaticRegistryFilters
{
    public const LIMIT = 300;

    private function __construct(
        public string $text,
        public ?NeumaticStatus $status,
        public ?int $branchId,
        public ?int $brandId,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($query['q'] ?? '')));

        return new self(
            mb_substr($text, 0, 60),
            NeumaticStatus::tryFrom(mb_strtoupper(trim((string) ($query['estado'] ?? '')))),
            self::positiveInt($query['sucursal'] ?? null),
            self::positiveInt($query['marca'] ?? null),
        );
    }

    /** @return array{q: string, estado: string, sucursal: int|null, marca: int|null} */
    public function toArray(): array
    {
        return [
            'q' => $this->text,
            'estado' => $this->status?->value ?? '',
            'sucursal' => $this->branchId,
            'marca' => $this->brandId,
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
