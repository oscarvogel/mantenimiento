<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\ReadingEvidenceReviewRepository;
use App\Domain\Measurement\ReadingEvidenceReviewReason;
use DateTimeImmutable;
use DomainException;
use ValueError;

final readonly class ReviewReadingEvidence
{
    public function __construct(private ReadingEvidenceReviewRepository $reviews)
    {
    }

    public function execute(ActorContext $actor, int $readingId, string $reasonValue, ?string $observation): void
    {
        $companyId = $actor->companyId();
        if ($companyId === null) {
            throw new DomainException('No existe una empresa válida para revisar la evidencia.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('No tenés sucursales autorizadas para revisar la evidencia.');
        }

        $target = $this->reviews->findScoped($companyId, $readingId, $branchIds);
        if ($target === null) {
            throw new DomainException('La evidencia no existe o queda fuera de tu alcance.');
        }

        if ($target->reviewedAt !== null) {
            throw new DomainException('La evidencia ya fue revisada.');
        }

        if (! $target->hasAiDiscrepancy()) {
            throw new DomainException('La evidencia no tiene una discrepancia de IA para revisar.');
        }

        try {
            $reason = ReadingEvidenceReviewReason::from(trim($reasonValue));
        } catch (ValueError) {
            throw new DomainException('El motivo de revisión no es válido.');
        }

        $observation = $observation === null ? null : trim($observation);
        if ($observation === '') {
            $observation = null;
        }
        if ($observation !== null && mb_strlen($observation) > 255) {
            throw new DomainException('La observación no puede superar los 255 caracteres.');
        }
        if ($reason->requiresObservation() && $observation === null) {
            throw new DomainException('Indicá una observación para el motivo seleccionado.');
        }

        $this->reviews->save(
            $companyId,
            $target,
            $reason,
            $observation,
            $actor->userId(),
            new DateTimeImmutable('now'),
        );
    }
}
