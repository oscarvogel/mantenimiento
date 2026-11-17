<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use PHPUnit\Framework\TestCase;

final class ReadingEvidenceReviewMigrationContractTest extends TestCase
{
    public function testMigrationCreatesOneReviewPerEvidenceWithTraceability(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Database/Migrations/2026-11-16-230000_CreateReadingEvidenceReviews.php');
        self::assertIsString($source);

        foreach ([
            'empresa_id',
            'evidencia_id',
            'lectura_id',
            'motivo',
            'observacion',
            'revisado_por',
            'revisado_at',
        ] as $field) {
            self::assertStringContainsString("'{$field}'", $source);
        }

        self::assertStringContainsString("addUniqueKey('evidencia_id'", $source);
        self::assertStringContainsString("createTable('lecturas_equipo_evidencias_revisiones'", $source);
    }
}
