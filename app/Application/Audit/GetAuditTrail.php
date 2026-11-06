<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Audit\Port\AuditTrailReadModel;
use App\Application\Identity\ActorContext;
use DateTimeImmutable;
use DomainException;

final class GetAuditTrail
{
    public function __construct(private readonly AuditTrailReadModel $readModel)
    {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     pages: int
     * }
     */
    public function execute(ActorContext $actor, array $filters, int $page = 1, int $perPage = 25): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La auditoría de cambios está disponible para el Superadministrador global.');
        }

        $normalized = [
            'companyId'   => $this->positiveInt($filters['companyId'] ?? null, 'empresa'),
            'branchId'    => $this->positiveInt($filters['branchId'] ?? null, 'sucursal'),
            'actorUserId' => $this->positiveInt($filters['actorUserId'] ?? null, 'usuario'),
            'action'      => $this->text($filters['action'] ?? null, 60, 'acción'),
            'entity'      => $this->text($filters['entity'] ?? null, 60, 'entidad'),
            'entityId'    => $this->text($filters['entityId'] ?? null, 64, 'identificador'),
            'dateFrom'    => $this->date($filters['dateFrom'] ?? null),
            'dateTo'      => $this->date($filters['dateTo'] ?? null),
            'search'      => $this->text($filters['search'] ?? null, 120, 'búsqueda'),
        ];

        if ($normalized['dateFrom'] !== null && $normalized['dateTo'] !== null
            && $normalized['dateFrom'] > $normalized['dateTo']) {
            throw new DomainException('La fecha desde no puede ser posterior a la fecha hasta.');
        }

        return $this->readModel->paginate(
            $normalized,
            max(1, $page),
            max(1, min(100, $perPage)),
        );
    }

    private function positiveInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new DomainException(sprintf('El filtro de %s no es válido.', $label));
        }

        return (int) $number;
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $text = trim((string) ($value ?? ''));
        if (mb_strlen($text) > $max) {
            throw new DomainException(sprintf('El filtro de %s es demasiado largo.', $label));
        }

        return $text;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Las fechas deben tener formato YYYY-MM-DD.');
        }

        return $value;
    }
}
