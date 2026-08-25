<?php

declare(strict_types=1);

use App\Application\Notifications\CompanyNotificationRecipientPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyNotificationRecipientPolicyTest extends TestCase
{
    public function testUsesDedicatedNotificationEmailBeforeGeneralEmail(): void
    {
        $policy = new CompanyNotificationRecipientPolicy();

        self::assertSame(
            'mantenimiento@empresa-a.test',
            $policy->resolve(' mantenimiento@empresa-a.test ', 'contacto@empresa-a.test', true),
        );
    }

    public function testFallsBackToGeneralCompanyEmail(): void
    {
        $policy = new CompanyNotificationRecipientPolicy();

        self::assertSame(
            'contacto@empresa-b.test',
            $policy->resolve(null, 'contacto@empresa-b.test', true),
        );
    }

    public function testDisabledOrMissingRecipientDoesNotProduceEmail(): void
    {
        $policy = new CompanyNotificationRecipientPolicy();

        self::assertNull($policy->resolve('alertas@empresa.test', 'contacto@empresa.test', false));
        self::assertNull($policy->resolve(null, null, true));
        self::assertNull($policy->resolve('correo-invalido', '', true));
    }

    public function testDifferentCompaniesRemainIndependentWhenResolved(): void
    {
        $policy = new CompanyNotificationRecipientPolicy();

        $companyA = $policy->resolve('alertas-a@empresa.test', 'general-a@empresa.test', true);
        $companyB = $policy->resolve('alertas-b@empresa.test', 'general-b@empresa.test', true);

        self::assertSame('alertas-a@empresa.test', $companyA);
        self::assertSame('alertas-b@empresa.test', $companyB);
        self::assertNotSame($companyA, $companyB);
    }
}
