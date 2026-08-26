<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\WorkOrders;

use App\Domain\WorkOrders\WorkOrderDocumentImport;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WorkOrderDocumentImportTest extends TestCase
{
    public function testCreatesValidImageImport(): void
    {
        $document = WorkOrderDocumentImport::create(
            3,
            7,
            11,
            'orden-taller.jpg',
            str_repeat('a', 48) . '.jpg',
            '3/' . str_repeat('a', 48) . '.jpg',
            'image/jpeg',
            2048,
            str_repeat('b', 64),
            'import-abc',
            new DateTimeImmutable('2026-08-26 09:00:00'),
        );

        self::assertSame(WorkOrderDocumentImport::STATUS_UPLOADED, $document->status());
        self::assertSame('image/jpeg', $document->mimeType());
        self::assertSame(3, $document->companyId());
    }

    public function testRejectsUnsupportedMime(): void
    {
        $this->expectException(DomainException::class);

        WorkOrderDocumentImport::create(
            3,
            7,
            11,
            'orden.txt',
            str_repeat('a', 48) . '.jpg',
            '3/' . str_repeat('a', 48) . '.jpg',
            'text/plain',
            1024,
            str_repeat('b', 64),
            'import-abc',
            new DateTimeImmutable(),
        );
    }

    public function testRejectsCrossCompanyPrivatePath(): void
    {
        $this->expectException(DomainException::class);

        WorkOrderDocumentImport::create(
            3,
            7,
            11,
            'orden.pdf',
            str_repeat('a', 48) . '.pdf',
            '4/' . str_repeat('a', 48) . '.pdf',
            'application/pdf',
            1024,
            str_repeat('b', 64),
            'import-abc',
            new DateTimeImmutable(),
        );
    }
}
