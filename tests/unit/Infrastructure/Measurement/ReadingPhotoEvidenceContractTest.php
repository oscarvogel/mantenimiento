<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Measurement;

use PHPUnit\Framework\TestCase;

final class ReadingPhotoEvidenceContractTest extends TestCase
{
    public function testPublicReadingRequiresPhotoAndKeepsManualFallback(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Controllers/PublicEquipmentReadings.php');
        self::assertIsString($source);

        self::assertStringContainsString("getFile('evidence_photo')", $source);
        self::assertStringContainsString('La foto del tablero es obligatoria.', $source);
        self::assertStringContainsString('MiniMaxOdometerImageAnalyzer::fromEnv()->analyze', $source);
        self::assertStringContainsString("env('uploads.privatePath'", $source);
        self::assertStringContainsString("'storageReady' => " . '$evidenceRef' . " !== null", $source);
        self::assertStringContainsString('mb_substr($aiObservation, 0, 255)', $source);
        self::assertStringContainsString('No se pudo guardar la evidencia de la lectura.', $source);
        self::assertStringContainsString("'FOTO_MANUAL'", $source);
        self::assertStringContainsString("'FOTO_IA_CONFIRMADA'", $source);
        self::assertStringContainsString("'FOTO_IA_CORREGIDA'", $source);
        self::assertStringContainsString('se continúa con carga manual', $source);
        self::assertStringContainsString('stagedEvidence(', $source);
        self::assertStringContainsString('public_reading_evidence_', $source);
    }

    public function testEvidenceStorageDoesNotProbeDockerOnlyPath(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Infrastructure/Measurement/ReadingEvidenceStorage.php');
        self::assertIsString($source);

        self::assertStringNotContainsString("is_dir('/data/priv')", $source);
        self::assertStringNotContainsString("'/data/priv/lecturas'", $source);
    }

    public function testControllerPrefersConfiguredEvidencePathAndLogsStagingFailureAsError(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Controllers/PublicEquipmentReadings.php');
        self::assertIsString($source);

        self::assertStringContainsString("env('uploads.readingEvidencePath'", $source);
        self::assertStringContainsString("env('uploads.privatePath'", $source);
        self::assertStringContainsString("log_message('error', 'No se pudo dejar staged la evidencia de lectura", $source);
    }

    public function testEvidenceDownloadIsTenantScoped(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Controllers/PublicEquipmentReadings.php');
        self::assertIsString($source);

        self::assertStringContainsString("->where('ev.empresa_id', " . '$actor' . "->companyId())", $source);
        self::assertStringContainsString("->join('lecturas_equipo le', 'le.id = ev.lectura_id AND le.empresa_id = ev.empresa_id'", $source);
    }

    public function testMobileViewUsesCameraUploadAndMultipartForm(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Views/public_equipment/reading.php');
        self::assertIsString($source);

        self::assertStringContainsString('enctype="multipart/form-data"', $source);
        self::assertStringContainsString('name="evidence_photo"', $source);
        self::assertStringContainsString('capture="environment"', $source);
        self::assertStringContainsString("photo.addEventListener('change', analyzePhoto)", $source);
        self::assertStringContainsString('name="evidence_ref"', $source);
        self::assertStringContainsString('payload.evidenceRef', $source);
        self::assertStringContainsString('setSubmitAvailable(false)', $source);
        self::assertStringContainsString('evidenceReady = true', $source);
        self::assertStringContainsString('photo.required = false', $source);
        self::assertStringContainsString('const hasDirectPhoto = Boolean(photo.files && photo.files[0])', $source);
        self::assertStringContainsString('La foto se enviará al registrar la lectura.', $source);
        self::assertStringContainsString('optimizePhoto(file)', $source);
        self::assertStringContainsString("photo.value = ''", $source);
        self::assertStringContainsString("'Preparando foto...'", $source);
    }
}
