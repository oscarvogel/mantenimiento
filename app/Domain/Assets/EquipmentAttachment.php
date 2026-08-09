<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use DateTimeImmutable;
use DomainException;

final class EquipmentAttachment
{
    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
    ];

    private function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $equipmentId,
        private readonly int $branchSnapshotId,
        private readonly string $type,
        private readonly string $originalName,
        private readonly string $storedName,
        private readonly string $privateRelativePath,
        private readonly string $mimeType,
        private readonly int $size,
        private readonly ?string $description,
        private readonly int $createdBy,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $retiredAt,
        private ?int $retiredBy,
        private ?string $retirementReason,
    ) {
    }

    public static function register(
        int $companyId,
        int $equipmentId,
        int $branchSnapshotId,
        string $type,
        string $originalName,
        string $storedName,
        string $privateRelativePath,
        string $mimeType,
        int $size,
        int $maximumSize,
        ?string $description,
        int $createdBy,
        DateTimeImmutable $createdAt,
    ): self {
        self::assertUpload($originalName, $mimeType, $size, $maximumSize);
        self::assertPositiveId($companyId, 'empresa');
        self::assertPositiveId($equipmentId, 'equipo');
        self::assertPositiveId($branchSnapshotId, 'sucursal');
        self::assertPositiveId($createdBy, 'usuario');

        $type = trim($type);
        if ($type === '' || strlen($type) > 50 || preg_match('/[\x00-\x1F\x7F]/', $type)) {
            throw new DomainException('El tipo de adjunto es obligatorio y admite hasta 50 caracteres.');
        }

        $description = $description === null ? null : trim($description);
        if ($description === '') {
            $description = null;
        }
        if ($description !== null && strlen($description) > 1000) {
            throw new DomainException('La descripción del adjunto admite hasta 1000 caracteres.');
        }

        $expectedExtension = self::canonicalExtension($mimeType);
        if (! preg_match('/^[a-f0-9]{48}\.' . preg_quote($expectedExtension, '/') . '$/', $storedName)) {
            throw new DomainException('El nombre almacenado del adjunto no es opaco o seguro.');
        }
        if ($privateRelativePath !== $companyId . '/' . $storedName) {
            throw new DomainException('La ruta privada del adjunto no pertenece a la empresa indicada.');
        }

        return new self(
            null,
            $companyId,
            $equipmentId,
            $branchSnapshotId,
            $type,
            trim($originalName),
            $storedName,
            $privateRelativePath,
            strtolower(trim($mimeType)),
            $size,
            $description,
            $createdBy,
            $createdAt,
            null,
            null,
            null,
        );
    }

    public static function reconstitute(
        int $id,
        int $companyId,
        int $equipmentId,
        int $branchSnapshotId,
        string $type,
        string $originalName,
        string $storedName,
        string $privateRelativePath,
        string $mimeType,
        int $size,
        ?string $description,
        int $createdBy,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $retiredAt,
        ?int $retiredBy,
        ?string $retirementReason,
    ): self {
        return new self(
            $id,
            $companyId,
            $equipmentId,
            $branchSnapshotId,
            $type,
            $originalName,
            $storedName,
            $privateRelativePath,
            $mimeType,
            $size,
            $description,
            $createdBy,
            $createdAt,
            $retiredAt,
            $retiredBy,
            $retirementReason,
        );
    }

    public static function assertUpload(string $originalName, string $mimeType, int $size, int $maximumSize): void
    {
        $originalName = trim($originalName);
        if ($originalName === '' || strlen($originalName) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
            throw new DomainException('El nombre original del adjunto no es válido.');
        }
        if (str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            throw new DomainException('El nombre original del adjunto no puede contener una ruta.');
        }
        if ($maximumSize <= 0 || $size <= 0 || $size > $maximumSize) {
            throw new DomainException('El adjunto está vacío o supera el tamaño máximo permitido.');
        }

        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (trim((string) pathinfo($originalName, PATHINFO_FILENAME)) === ''
            || ! isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])
            || ! in_array($extension, self::ALLOWED_MIME_EXTENSIONS[$mimeType], true)) {
            throw new DomainException('El tipo real y la extensión del adjunto no están permitidos.');
        }
    }

    public static function canonicalExtension(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (! isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])) {
            throw new DomainException('El tipo real del adjunto no está permitido.');
        }

        return self::ALLOWED_MIME_EXTENSIONS[$mimeType][0];
    }

    public function retire(int $actorUserId, DateTimeImmutable $when, string $reason): void
    {
        if ($this->retiredAt !== null) {
            throw new DomainException('El adjunto ya fue retirado.');
        }
        self::assertPositiveId($actorUserId, 'usuario');
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255) {
            throw new DomainException('El motivo de retiro es obligatorio y admite hasta 255 caracteres.');
        }

        $this->retiredAt = $when;
        $this->retiredBy = $actorUserId;
        $this->retirementReason = $reason;
    }

    private static function assertPositiveId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new DomainException(sprintf('El identificador de %s no es válido.', $label));
        }
    }

    public function id(): ?int { return $this->id; }
    public function companyId(): int { return $this->companyId; }
    public function equipmentId(): int { return $this->equipmentId; }
    public function branchSnapshotId(): int { return $this->branchSnapshotId; }
    public function type(): string { return $this->type; }
    public function originalName(): string { return $this->originalName; }
    public function storedName(): string { return $this->storedName; }
    public function privateRelativePath(): string { return $this->privateRelativePath; }
    public function mimeType(): string { return $this->mimeType; }
    public function size(): int { return $this->size; }
    public function description(): ?string { return $this->description; }
    public function createdBy(): int { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function retiredAt(): ?DateTimeImmutable { return $this->retiredAt; }
    public function retiredBy(): ?int { return $this->retiredBy; }
    public function retirementReason(): ?string { return $this->retirementReason; }
}
