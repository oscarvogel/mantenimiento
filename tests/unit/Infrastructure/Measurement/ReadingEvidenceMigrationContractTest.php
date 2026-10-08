<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use PHPUnit\Framework\TestCase;

final class ReadingEvidenceMigrationContractTest extends TestCase
{
    public function testMigrationPersistsTraceabilityFieldsAndOneEvidencePerReading(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Database/Migrations/2026-10-08-090000_CreateReadingEvidenceTable.php');
        self::assertIsString($source);

        foreach ([
            'empresa_id',
            'lectura_id',
            'archivo_path',
            'mime_type',
            'km_detectado_ia',
            'confianza_ia',
            'estado_ia',
            'km_confirmado',
            'metodo_carga',
        ] as $field) {
            self::assertStringContainsString("'{$field}'", $source);
        }

        self::assertStringContainsString("addUniqueKey('lectura_id'", $source);
        self::assertStringContainsString("createTable('lecturas_equipo_evidencias')", $source);
    }
}
