<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use PHPUnit\Framework\TestCase;

final class ReadingEvidenceHistoryContractTest extends TestCase
{
    public function testEquipmentHistoryExposesEvidenceAndKeepsViewerInsideApp(): void
    {
        $readModel = file_get_contents(ROOTPATH . 'app/Infrastructure/Measurement/CodeIgniterReadingHistory.php');
        $payload = file_get_contents(ROOTPATH . 'app/Presentation/OperationsPayload.php');
        $vue = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentDetailPage.vue');

        self::assertIsString($readModel);
        self::assertIsString($payload);
        self::assertIsString($vue);

        self::assertStringContainsString('lecturas_equipo_evidencias ev', $readModel);
        self::assertStringContainsString('evidenceUrl', $payload);
        self::assertStringContainsString('readingEvidence = ref(null)', $vue);
        self::assertStringContainsString('Evidencia de lectura', $vue);
        self::assertStringContainsString('@click="readingEvidence = reading"', $vue);
        self::assertStringNotContainsString('target="_blank" rel="noopener" class="font-semibold text-primary hover:underline"', $vue);
    }
}
