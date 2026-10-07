<?php

declare(strict_types=1);

use App\Domain\Notifications\WhatsAppPhone;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppPhoneTest extends CIUnitTestCase
{
    public function testNormalizesSupportedInternationalPhone(): void
    {
        self::assertSame('5493764123456', WhatsAppPhone::normalize(' 5493764123456 '));
    }

    public function testEmptyPhoneIsOptional(): void
    {
        self::assertNull(WhatsAppPhone::normalize(''));
    }

    public function testRejectsArgentinePhoneWithoutMobileNine(): void
    {
        self::assertNull(WhatsAppPhone::normalize('543764123456'));
    }

    public function testRejectsLocalPhoneWithoutCountryCode(): void
    {
        self::assertNull(WhatsAppPhone::normalize('3764123456'));
    }
}
