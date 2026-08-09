<?php

declare(strict_types=1);

namespace App\Domain\Importations;

use DomainException;

final class ImportBatch
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ImportType $type,
        private ImportStatus $status,
    ) {
        if ($id <= 0 || $companyId <= 0) {
            throw new DomainException('La importacion requiere identidades validas.');
        }
    }

    public function status(): ImportStatus
    {
        return $this->status;
    }

    public function confirm(): void
    {
        if (! $this->status->canConfirm()) {
            throw new DomainException('Solo un borrador validado puede confirmarse.');
        }
        $this->status = ImportStatus::CONFIRMADO;
    }

    public function cancel(): void
    {
        if (! $this->status->canCancel()) {
            throw new DomainException('Solo un borrador validado puede cancelarse.');
        }
        $this->status = ImportStatus::CANCELADO;
    }
}
