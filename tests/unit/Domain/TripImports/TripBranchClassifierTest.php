<?php

declare(strict_types=1);

use App\Domain\TripImports\TripBranchClassifier;
use PHPUnit\Framework\TestCase;

final class TripBranchClassifierTest extends TestCase
{
    public function testMercosurPlateFormatsResolveCountry(): void
    {
        $classifier = new TripBranchClassifier();

        self::assertSame(TripBranchClassifier::BR, $classifier->domainKind('IUU5G88'));
        self::assertSame(TripBranchClassifier::AR, $classifier->domainKind('AC 532 DD'));
        self::assertSame(TripBranchClassifier::AMBIGUOUS, $classifier->domainKind('GZD 587'));
        self::assertSame(TripBranchClassifier::UNKNOWN, $classifier->domainKind('AA PRUEBA'));
    }

    public function testEvidenceFindsArgentinaAndBrazil(): void
    {
        $classifier = new TripBranchClassifier();

        $argentina = $classifier->evidence('IDA PAPEL MISIONERO PUERTO MINERAL Y REGRESO ROSARIO');
        self::assertGreaterThan(0, $argentina[TripBranchClassifier::AR]);
        self::assertSame(0, $argentina[TripBranchClassifier::BR]);

        $brazil = $classifier->evidence('VIAJE A SAO PAULO Y CURITIBA');
        self::assertGreaterThan(0, $brazil[TripBranchClassifier::BR]);
        self::assertSame(0, $brazil[TripBranchClassifier::AR]);
    }

    public function testAmbiguousDomainUsesMajorityAndLeavesTiesUnclassified(): void
    {
        $classifier = new TripBranchClassifier();

        self::assertSame(TripBranchClassifier::AR, $classifier->resolveAmbiguous(3, 1));
        self::assertSame(TripBranchClassifier::BR, $classifier->resolveAmbiguous(1, 3));
        self::assertNull($classifier->resolveAmbiguous(1, 1));
        self::assertNull($classifier->resolveAmbiguous(0, 0));
    }
}
