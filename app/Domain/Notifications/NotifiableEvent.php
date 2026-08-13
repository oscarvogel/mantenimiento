<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class NotifiableEvent
{
    public function __construct(
        private int $companyId,
        private ?int $branchId,
        private string $type,
        private NotificationSeverity $severity,
        private string $title,
        private string $summary,
        private string $entityType,
        private string $entityId,
        private string $logicalKey,
        private ?string $url,
        private DateTimeImmutable $occurredAt,
        private ?array $recipientUserIds = null,
    ) {
        if ($companyId <= 0 || ($branchId !== null && $branchId <= 0)) {
            throw new InvalidArgumentException('El evento notificable requiere un alcance válido.');
        }
        foreach (['type' => $type, 'title' => $title, 'summary' => $summary, 'entityType' => $entityType, 'entityId' => $entityId, 'logicalKey' => $logicalKey] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("El campo {$name} del evento notificable es obligatorio.");
            }
        }
        if (strlen($logicalKey) > 190 || preg_match('/^[a-z0-9_.:-]+$/i', $logicalKey) !== 1) {
            throw new InvalidArgumentException('La clave lógica del evento no es válida.');
        }
        if ($url !== null && (! str_starts_with($url, '/') || str_starts_with($url, '//'))) {
            throw new InvalidArgumentException('El enlace de una notificación debe ser una ruta interna.');
        }
        if ($recipientUserIds !== null && array_filter($recipientUserIds, static fn (mixed $id): bool => ! is_int($id) || $id <= 0) !== []) {
            throw new InvalidArgumentException('Los destinatarios explícitos del evento no son válidos.');
        }
    }

    public function companyId(): int { return $this->companyId; }
    public function branchId(): ?int { return $this->branchId; }
    public function type(): string { return $this->type; }
    public function severity(): NotificationSeverity { return $this->severity; }
    public function title(): string { return $this->title; }
    public function summary(): string { return $this->summary; }
    public function entityType(): string { return $this->entityType; }
    public function entityId(): string { return $this->entityId; }
    public function logicalKey(): string { return $this->logicalKey; }
    public function url(): ?string { return $this->url; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
    /** @return list<int>|null */ public function recipientUserIds(): ?array { return $this->recipientUserIds; }
}
