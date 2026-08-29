<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;
use DomainException;

final class WorkOrderDocumentImport
{
    public const STATUS_UPLOADED = 'SUBIDO';
    public const STATUS_ANALYZING = 'ANALIZANDO';
    public const STATUS_ANALYZED = 'ANALIZADO';
    public const STATUS_CONFIRMED = 'CONFIRMADO';
    public const STATUS_ERROR = 'ERROR';

    /** @var list<string> */
    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $branchId,
        private readonly int $createdBy,
        private readonly string $originalName,
        private readonly string $storedName,
        private readonly string $privateRelativePath,
        private readonly string $mimeType,
        private readonly int $sizeBytes,
        private readonly string $sha256,
        private readonly string $idempotencyKey,
        private string $status,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        int $companyId,
        int $branchId,
        int $createdBy,
        string $originalName,
        string $storedName,
        string $privateRelativePath,
        string $mimeType,
        int $sizeBytes,
        string $sha256,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
        int $maxSizeBytes = 10_485_760,
    ): self {
        foreach ([$companyId, $branchId, $createdBy] as $id) {
            if ($id <= 0) {
                throw new DomainException('Empresa, sucursal y usuario son obligatorios.');
            }
        }
        $originalName = trim($originalName);
        if ($originalName === '' || strlen($originalName) > 255 || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            throw new DomainException('El nombre del documento no es válido.');
        }
        $mimeType = strtolower(trim($mimeType));
        if (! in_array($mimeType, self::ALLOWED_MIMES, true)) {
            throw new DomainException('Solo se admiten documentos JPG, PNG o PDF.');
        }
        if ($sizeBytes <= 0 || $sizeBytes > $maxSizeBytes) {
            throw new DomainException('El documento está vacío o supera el tamaño máximo permitido.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new DomainException('La huella SHA-256 del documento no es válida.');
        }
        if (! preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', $storedName)) {
            throw new DomainException('El nombre privado del documento no es válido.');
        }
        if ($privateRelativePath !== $companyId . '/' . $storedName) {
            throw new DomainException('La ruta privada del documento no pertenece a la empresa.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new DomainException('La clave de idempotencia no es válida.');
        }

        return new self(null, $companyId, $branchId, $createdBy, $originalName, $storedName, $privateRelativePath, $mimeType, $sizeBytes, $sha256, $idempotencyKey, self::STATUS_UPLOADED, $createdAt);
    }

    public function markAnalyzing(): void
    {
        if (! in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_ERROR], true)) {
            throw new DomainException('El documento no puede analizarse desde su estado actual.');
        }
        $this->status = self::STATUS_ANALYZING;
    }

    public function markAnalyzed(): void { $this->status = self::STATUS_ANALYZED; }
    public function markError(): void { $this->status = self::STATUS_ERROR; }
    public function markConfirmed(): void { $this->status = self::STATUS_CONFIRMED; }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function branchId(): int { return $this->branchId; }
    public function createdBy(): int { return $this->createdBy; }
    public function originalName(): string { return $this->originalName; }
    public function storedName(): string { return $this->storedName; }
    public function privateRelativePath(): string { return $this->privateRelativePath; }
    public function mimeType(): string { return $this->mimeType; }
    public function sizeBytes(): int { return $this->sizeBytes; }
    public function sha256(): string { return $this->sha256; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function status(): string { return $this->status; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
