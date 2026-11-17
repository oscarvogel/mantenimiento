<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Measurement;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\ReadingEvidenceReviewRepository;
use App\Application\Measurement\ReadingEvidenceReviewTarget;
use App\Application\Measurement\ReviewReadingEvidence;
use App\Domain\Measurement\ReadingEvidenceReviewReason;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ReviewReadingEvidenceTest extends TestCase
{
    public function testItSavesAValidReview(): void
    {
        $target = $this->target();
        $repository = new FakeReadingEvidenceReviewRepository($target);
        $useCase = new ReviewReadingEvidence($repository);

        $useCase->execute($this->actor(), 99, ReadingEvidenceReviewReason::FALSE_POSITIVE->value, null);

        self::assertNotNull($repository->saved);
        self::assertSame($target->evidenceId, $repository->saved['target']->evidenceId);
        self::assertSame(ReadingEvidenceReviewReason::FALSE_POSITIVE, $repository->saved['reason']);
        self::assertNull($repository->saved['observation']);
    }

    public function testItRejectsAnAlreadyReviewedEvidence(): void
    {
        $repository = new FakeReadingEvidenceReviewRepository($this->target(new DateTimeImmutable('2026-11-16 10:00:00')));

        $this->expectException(DomainException::class);
        (new ReviewReadingEvidence($repository))->execute(
            $this->actor(),
            99,
            ReadingEvidenceReviewReason::FALSE_POSITIVE->value,
            null,
        );
    }

    public function testItRejectsAnEvidenceWithoutDiscrepancy(): void
    {
        $repository = new FakeReadingEvidenceReviewRepository($this->target(null, null, 507738, 'FOTO_IA_CONFIRMADA'));

        $this->expectException(DomainException::class);
        (new ReviewReadingEvidence($repository))->execute(
            $this->actor(),
            99,
            ReadingEvidenceReviewReason::FALSE_POSITIVE->value,
            null,
        );
    }

    public function testItRequiresObservationForOtherReason(): void
    {
        $repository = new FakeReadingEvidenceReviewRepository($this->target());

        $this->expectException(DomainException::class);
        (new ReviewReadingEvidence($repository))->execute(
            $this->actor(),
            99,
            ReadingEvidenceReviewReason::OTHER->value,
            null,
        );
    }

    public function testItRejectsAnInvalidReason(): void
    {
        $repository = new FakeReadingEvidenceReviewRepository($this->target());

        $this->expectException(DomainException::class);
        (new ReviewReadingEvidence($repository))->execute($this->actor(), 99, 'NO_EXISTE', null);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(7, 4, false, false, ['Administrador'], ['lecturas.cargar'], [11]);
    }

    private function target(
        ?DateTimeImmutable $reviewedAt = null,
        ?int $aiDetected = 17,
        ?int $confirmed = 507738,
        ?string $method = 'FOTO_IA_CORREGIDA',
    ): ReadingEvidenceReviewTarget {
        return new ReadingEvidenceReviewTarget(
            5,
            99,
            10,
            11,
            'RHB1B55',
            $method,
            $aiDetected,
            $confirmed,
            $reviewedAt,
            $reviewedAt === null ? null : 'Admin',
            $reviewedAt === null ? null : 'FALSO_POSITIVO_IA',
            null,
        );
    }
}

final class FakeReadingEvidenceReviewRepository implements ReadingEvidenceReviewRepository
{
    /** @var array{target: ReadingEvidenceReviewTarget, reason: ReadingEvidenceReviewReason, observation: ?string, actor: int, at: DateTimeImmutable}|null */
    public ?array $saved = null;

    public function __construct(private ?ReadingEvidenceReviewTarget $target)
    {
    }

    public function findScoped(int $companyId, int $readingId, ?array $branchIds): ?ReadingEvidenceReviewTarget
    {
        return $this->target;
    }

    public function save(
        int $companyId,
        ReadingEvidenceReviewTarget $target,
        ReadingEvidenceReviewReason $reason,
        ?string $observation,
        int $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $this->saved = [
            'target' => $target,
            'reason' => $reason,
            'observation' => $observation,
            'actor' => $actorUserId,
            'at' => $at,
        ];
    }
}
