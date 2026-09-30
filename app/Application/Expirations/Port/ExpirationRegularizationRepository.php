<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Domain\Expirations\ExpirationRegularization;

interface ExpirationRegularizationRepository
{
    public function hasPending(int $companyId, int $expirationId): bool;

    public function createPending(ExpirationRegularization $regularization): int;

    public function reject(int $companyId, int $regularizationId, int $reviewerUserId, string $reason, string $reviewedAt): void;

    public function approve(int $companyId, int $regularizationId, int $reviewerUserId, string $reviewedAt): void;

    public function addAttachment(int $companyId, int $regularizationId, string $storedPath, string $originalName, string $mimeType, int $sizeBytes): int;
}
