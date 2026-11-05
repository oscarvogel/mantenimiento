<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\ReadingReceiptMessageBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReadingReceiptMessageBuilderTest extends TestCase
{
    public function testItConfirmsTheSavedKilometersWithAReceipt(): void
    {
        $message = $this->build();

        self::assertStringContainsString('Transportes del Sur', $message);
        self::assertStringContainsString('Lectura registrada', $message);
        self::assertStringContainsString('Hola Juan 👋', $message);
        self::assertStringContainsString('Equipo: *CAM-01 · AA123BB*', $message);
        self::assertStringContainsString('Kilometraje: *494.497 km*', $message);
        self::assertStringContainsString('05/11/2026 08:14', $message);
        self::assertStringContainsString('La foto fue validada por IA.', $message);
        self::assertStringContainsString('Comprobante: *L-000123*', $message);
        self::assertStringContainsString('No necesitás hacer nada más.', $message);
    }

    public function testItExplainsWhenTheAiValueWasCorrected(): void
    {
        $message = $this->build(
            method: ReadingReceiptMessageBuilder::METHOD_AI_CORRECTED,
            detectedKm: 495000,
        );

        self::assertStringContainsString('La IA había leído 495.000 km', $message);
        self::assertStringContainsString('quedó registrado el valor que confirmaste', $message);
    }

    public function testItExplainsAManualEntry(): void
    {
        $message = $this->build(method: ReadingReceiptMessageBuilder::METHOD_MANUAL);

        self::assertStringContainsString('Registramos el valor que ingresaste.', $message);
    }

    public function testItFlagsAReadingThatNeedsReview(): void
    {
        $message = $this->build(needsReview: true);

        self::assertStringContainsString('mantenimiento lo va a revisar', $message);
        self::assertStringNotContainsString('aprobado', mb_strtolower($message));
    }

    public function testItShowsHoursWhenTheEquipmentTracksHours(): void
    {
        $message = $this->build(kilometers: null, hours: 1234.5);

        self::assertStringContainsString('Horómetro: *1.234,5 h*', $message);
        self::assertStringNotContainsString('Kilometraje:', $message);
    }

    public function testItShowsBothValuesWhenBothExist(): void
    {
        $message = $this->build(hours: 12.3);

        self::assertStringContainsString('Kilometraje: *494.497 km*', $message);
        self::assertStringContainsString('Horómetro: *12,3 h*', $message);
    }

    public function testItWritesInPortuguese(): void
    {
        $message = $this->build(locale: 'PT', company: 'Transportes do Sul');

        self::assertStringContainsString('Manutenção', $message);
        self::assertStringContainsString('Leitura registrada', $message);
        self::assertStringContainsString('Olá Juan 👋', $message);
        self::assertStringContainsString('Comprovante: *L-000123*', $message);
        self::assertStringContainsString('Não precisa fazer mais nada.', $message);
    }

    public function testPilotModeIsAnnounced(): void
    {
        $message = $this->build(pilotEnabled: true);

        self::assertStringContainsString('PRUEBA CONTROLADA', $message);
        self::assertStringContainsString('NO ENVIADO AL DESTINATARIO REAL', $message);
    }

    public function testItHandlesAnEmptyDriverName(): void
    {
        $message = $this->build(driver: '');

        self::assertStringContainsString('Hola 👋', $message);
        self::assertStringNotContainsString('Hola  👋', $message);
    }

    public function testReceiptCodeKeepsGrowingWithoutTruncation(): void
    {
        self::assertSame('L-000001', ReadingReceiptMessageBuilder::receiptCode(1));
        self::assertSame('L-123456', ReadingReceiptMessageBuilder::receiptCode(123456));
        self::assertSame('L-1000000', ReadingReceiptMessageBuilder::receiptCode(1000000));
    }

    private function build(
        string $locale = 'ES',
        string $company = 'Transportes del Sur',
        string $driver = 'Juan Pérez',
        int $readingId = 123,
        ?int $kilometers = 494497,
        ?float $hours = null,
        string $method = ReadingReceiptMessageBuilder::METHOD_AI_CONFIRMED,
        ?int $detectedKm = null,
        bool $needsReview = false,
        bool $pilotEnabled = false,
    ): string {
        return (new ReadingReceiptMessageBuilder())->build(
            $locale,
            $company,
            $driver,
            'CAM-01 · AA123BB',
            $kilometers,
            $hours,
            $method,
            $detectedKm,
            $needsReview,
            new DateTimeImmutable('2026-11-05 08:14:00'),
            $readingId,
            $pilotEnabled,
            true,
        );
    }
}
