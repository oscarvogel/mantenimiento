<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\ReceiptAttachmentRules;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ReceiptAttachmentRulesTest extends TestCase
{
    public function testAcceptsPdfAndImagesWithMatchingExtension(): void
    {
        ReceiptAttachmentRules::assertUpload('transferencia.pdf', 'application/pdf', 2048);
        ReceiptAttachmentRules::assertUpload('cheque.jpg', 'image/jpeg', 2048);
        ReceiptAttachmentRules::assertUpload('foto.png', 'image/png', 2048);
        ReceiptAttachmentRules::assertUpload('foto.webp', 'image/webp', 2048);

        self::assertSame('pdf', ReceiptAttachmentRules::canonicalExtension('application/pdf'));
        self::assertSame('jpg', ReceiptAttachmentRules::canonicalExtension('image/jpeg'));
    }

    public function testRejectsMismatchedRealTypeAndExtension(): void
    {
        $this->expectExceptionMessage('no están permitidos');
        ReceiptAttachmentRules::assertUpload('factura.pdf', 'image/png', 2048);
    }

    public function testRejectsPathsAndOversizedFiles(): void
    {
        foreach ([
            static fn () => ReceiptAttachmentRules::assertUpload('../factura.pdf', 'application/pdf', 2048),
            static fn () => ReceiptAttachmentRules::assertUpload('factura.pdf', 'application/pdf', ReceiptAttachmentRules::MAXIMUM_BYTES + 1),
            static fn () => ReceiptAttachmentRules::assertUpload('factura.exe', 'application/pdf', 2048),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('El adjunto debía rechazarse.');
            } catch (DomainException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
