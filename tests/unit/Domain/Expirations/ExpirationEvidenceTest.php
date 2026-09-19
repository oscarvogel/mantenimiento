<?php

declare(strict_types=1);

use App\Domain\Expirations\ExpirationEvidence;
use PHPUnit\Framework\TestCase;

final class ExpirationEvidenceTest extends TestCase
{
    public function testRegistersPdfEvidence(): void
    {
        $evidence = $this->createEvidence('application/pdf', 'pdf', 'documento.pdf');
        self::assertSame('application/pdf', $evidence->mimeType);
        self::assertSame('documento.pdf', $evidence->originalName);
    }

    public function testRegistersJpegEvidence(): void
    {
        $evidence = $this->createEvidence('image/jpeg', 'jpg', 'foto.jpg');
        self::assertSame('image/jpeg', $evidence->mimeType);
    }

    public function testRegistersWebpEvidence(): void
    {
        $evidence = $this->createEvidence('image/webp', 'webp', 'foto.webp');
        self::assertSame('image/webp', $evidence->mimeType);
    }

    public function testRejectsMimeOutsideAllowlist(): void
    {
        $this->expectException(DomainException::class);
        $this->createEvidence('application/x-msdownload', 'exe', 'payload.exe');
    }

    public function testRejectsMimeMismatchWithExtension(): void
    {
        // Extension .jpg con mime pdf no es consistente: spoofing.
        $this->expectException(DomainException::class);
        $this->createEvidence('application/pdf', 'jpg', 'foto.jpg');
    }

    public function testRejectsPathTraversalInOriginalName(): void
    {
        $this->expectException(DomainException::class);
        $this->createEvidence('application/pdf', 'pdf', '../etc/passwd.pdf');
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(DomainException::class);
        $this->createEvidence('application/pdf', 'pdf', 'doc.pdf', 0);
    }

    public function testRejectsStoredNameOutsideHexConvention(): void
    {
        $this->expectException(DomainException::class);
        $evidence = new ExpirationEvidence(
            5,
            10,
            'documento.pdf',
            'unsafe.pdf',
            '5/unsafe.pdf',
            'application/pdf',
            1024,
            21,
            new DateTimeImmutable(),
        );
        self::assertSame(5, $evidence->companyId);
    }

    public function testRejectsPrivatePathMismatchWithCompany(): void
    {
        $this->expectException(DomainException::class);
        $storedName = str_repeat('c', 48) . '.pdf';
        new ExpirationEvidence(
            5,
            10,
            'documento.pdf',
            $storedName,
            '99/' . $storedName,
            'application/pdf',
            1024,
            21,
            new DateTimeImmutable(),
        );
    }

    private function createEvidence(string $mimeType, string $extension, string $name, int $size = 1024): ExpirationEvidence
    {
        $storedName = str_repeat('a', 48) . '.' . $extension;

        return new ExpirationEvidence(
            5,
            10,
            $name,
            $storedName,
            '5/' . $storedName,
            $mimeType,
            $size,
            21,
            new DateTimeImmutable(),
        );
    }
}
