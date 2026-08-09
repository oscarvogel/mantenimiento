<?php

declare(strict_types=1);

use App\Domain\Assets\EquipmentAttachment;
use PHPUnit\Framework\TestCase;

final class EquipmentAttachmentTest extends TestCase
{
    public function testRegistersAllowedPrivateAttachmentAndRetiresWithoutChangingItsFileIdentity(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-08 10:00:00');
        $storedName = str_repeat('a', 48) . '.pdf';
        $attachment = EquipmentAttachment::register(
            5,
            8,
            13,
            'MANUAL',
            'manual.pdf',
            $storedName,
            '5/' . $storedName,
            'application/pdf',
            1234,
            2048,
            'Manual de servicio',
            21,
            $createdAt,
        );

        $attachment->retire(22, new DateTimeImmutable('2026-08-08 11:00:00'), 'Documento reemplazado');

        self::assertSame('5/' . $storedName, $attachment->privateRelativePath());
        self::assertSame(22, $attachment->retiredBy());
        self::assertSame('Documento reemplazado', $attachment->retirementReason());
    }

    /** @dataProvider invalidUploadProvider */
    public function testRejectsUnsafeOrInconsistentUpload(
        string $originalName,
        string $mimeType,
        int $size,
        int $maximumSize,
    ): void {
        $this->expectException(DomainException::class);
        EquipmentAttachment::assertUpload($originalName, $mimeType, $size, $maximumSize);
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function invalidUploadProvider(): iterable
    {
        yield 'executable' => ['payload.exe', 'application/x-dosexec', 100, 1000];
        yield 'spoofed extension' => ['foto.jpg', 'application/pdf', 100, 1000];
        yield 'path traversal' => ['../manual.pdf', 'application/pdf', 100, 1000];
        yield 'header injection' => ["manual.pdf\r\nX-Test: injected", 'application/pdf', 100, 1000];
        yield 'empty file' => ['manual.pdf', 'application/pdf', 0, 1000];
        yield 'oversized file' => ['manual.pdf', 'application/pdf', 1001, 1000];
    }

    public function testRejectsPrivatePathThatDoesNotBelongToCompany(): void
    {
        $storedName = str_repeat('b', 48) . '.png';

        $this->expectException(DomainException::class);
        EquipmentAttachment::register(
            5,
            8,
            13,
            'FOTO',
            'foto.png',
            $storedName,
            '6/' . $storedName,
            'image/png',
            50,
            100,
            null,
            21,
            new DateTimeImmutable(),
        );
    }
}
