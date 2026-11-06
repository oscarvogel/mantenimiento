<?php

declare(strict_types=1);

use App\Domain\Geography\PrincipalLocalityPolicy;
use PHPUnit\Framework\TestCase;

final class PrincipalLocalityPolicyTest extends TestCase
{
    public function testBrazilRequiresAConfiguredMunicipalityAndCityCategory(): void
    {
        $policy = new PrincipalLocalityPolicy(['3550308']);

        self::assertTrue($policy->isPrincipal('BRASIL', 'Cidade / Sede Municipal', '3550308'));
        self::assertTrue($policy->isPrincipal('BRASIL', 'Cidade / Capital Estadual | Cidade / Sede Municipal', '3550308'));
        self::assertFalse($policy->isPrincipal('BRASIL', 'Vila', '3550308'));
        self::assertFalse($policy->isPrincipal('BRASIL', 'Cidade / Sede Municipal', '4125506'));
    }

    public function testChileKeepsCitiesAndDiscardsSmallerSettlements(): void
    {
        $policy = new PrincipalLocalityPolicy([]);

        self::assertTrue($policy->isPrincipal('CHILE', 'CIUDAD', '3599'));
        self::assertFalse($policy->isPrincipal('CHILE', 'ALDEA', '3600'));
    }

    public function testUruguayKeepsItsSmallCensusCatalog(): void
    {
        $policy = new PrincipalLocalityPolicy([]);

        self::assertTrue($policy->isPrincipal('URUGUAY', 'LOCALIDAD CENSAL', 'UYMOMVD'));
    }

    public function testArgentinaUsesProvinceEntriesInsteadOfOfficialLocalities(): void
    {
        $policy = new PrincipalLocalityPolicy([]);

        self::assertFalse($policy->isPrincipal('ARGENTINA', 'Localidad simple', '30008050'));
    }

    public function testBrazilListKeepsAllStateCapitalsAndTheExpectedSize(): void
    {
        $codes = (new \Config\LocalityCatalog())->brazilPrincipalMunicipalityCodes;

        self::assertCount(200, $codes);
        foreach ([
            '1100205', '1200401', '1302603', '1400100', '1501402', '1600303', '1721000',
            '2111300', '2211001', '2304400', '2408102', '2507507', '2611606', '2704302',
            '2800308', '2927408', '3106200', '3205309', '3304557', '3550308', '4106902',
            '4205407', '4314902', '5002704', '5103403', '5208707', '5300108',
        ] as $capital) {
            self::assertContains($capital, $codes);
        }
    }
}
