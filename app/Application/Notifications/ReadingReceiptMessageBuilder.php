<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use DateTimeImmutable;

final class ReadingReceiptMessageBuilder
{
    public const METHOD_AI_CONFIRMED = 'FOTO_IA_CONFIRMADA';
    public const METHOD_AI_CORRECTED = 'FOTO_IA_CORREGIDA';
    public const METHOD_MANUAL = 'FOTO_MANUAL';

    public static function receiptCode(int $readingId): string
    {
        return 'L-' . str_pad((string) max(0, $readingId), 6, '0', STR_PAD_LEFT);
    }

    public function build(
        string $locale,
        string $companyName,
        string $driverFullName,
        string $equipmentLabel,
        ?int $kilometers,
        ?float $hours,
        string $method,
        ?int $detectedKm,
        bool $needsReview,
        DateTimeImmutable $registeredAt,
        int $readingId,
        bool $pilotEnabled = false,
        bool $realPhoneValid = false,
    ): string {
        $locale = strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
        $companyName = trim($companyName) !== '' ? trim($companyName) : ($locale === 'PT' ? 'Empresa' : 'Empresa');
        $equipmentLabel = trim($equipmentLabel) !== '' ? trim($equipmentLabel) : ($locale === 'PT' ? 'Equipamento' : 'Equipo');
        $firstName = trim(explode(' ', trim($driverFullName))[0] ?? '');
        $date = $registeredAt->format('d/m/Y H:i');
        $code = self::receiptCode($readingId);

        $pilotHeader = $pilotEnabled
            ? (new PilotMessageHeader())->build($locale, $driverFullName, $realPhoneValid)
            : '';

        if ($locale === 'PT') {
            $lines = [];
            if ($kilometers !== null) {
                $lines[] = 'Quilometragem: *' . number_format((float) $kilometers, 0, ',', '.') . ' km*';
            }
            if ($hours !== null) {
                $lines[] = 'Horímetro: *' . number_format($hours, 1, ',', '.') . ' h*';
            }

            return $pilotHeader
                . '*' . $companyName . "* · Manutenção\n\n"
                . "✅ *Leitura registrada*\n\n"
                . ($firstName === '' ? 'Olá 👋' : 'Olá ' . $firstName . ' 👋') . "\n\n"
                . 'Equipamento: *' . $equipmentLabel . "*\n"
                . implode("\n", $lines) . "\n"
                . $date . "\n\n"
                . $this->statusText('PT', $method, $detectedKm, $needsReview) . "\n\n"
                . 'Comprovante: *' . $code . "*\n\n"
                . 'Não precisa fazer mais nada.';
        }

        $lines = [];
        if ($kilometers !== null) {
            $lines[] = 'Kilometraje: *' . number_format((float) $kilometers, 0, ',', '.') . ' km*';
        }
        if ($hours !== null) {
            $lines[] = 'Horómetro: *' . number_format($hours, 1, ',', '.') . ' h*';
        }

        return $pilotHeader
            . '*' . $companyName . "* · Mantenimiento\n\n"
            . "✅ *Lectura registrada*\n\n"
            . ($firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋') . "\n\n"
            . 'Equipo: *' . $equipmentLabel . "*\n"
            . implode("\n", $lines) . "\n"
            . $date . "\n\n"
            . $this->statusText('ES', $method, $detectedKm, $needsReview) . "\n\n"
            . 'Comprobante: *' . $code . "*\n\n"
            . 'No necesitás hacer nada más.';
    }

    private function statusText(string $locale, string $method, ?int $detectedKm, bool $needsReview): string
    {
        $method = strtoupper(trim($method));

        if ($needsReview) {
            return $locale === 'PT'
                ? 'A foto e o valor carregado não coincidem; a manutenção vai revisar.'
                : 'La foto y el valor cargado no coinciden; mantenimiento lo va a revisar.';
        }

        if ($method === self::METHOD_AI_CONFIRMED) {
            return $locale === 'PT'
                ? 'A foto foi validada pela IA.'
                : 'La foto fue validada por IA.';
        }

        if ($method === self::METHOD_AI_CORRECTED && $detectedKm !== null) {
            $detected = number_format((float) $detectedKm, 0, ',', '.') . ' km';

            return $locale === 'PT'
                ? 'A IA havia lido ' . $detected . '; ficou registrado o valor que você confirmou.'
                : 'La IA había leído ' . $detected . '; quedó registrado el valor que confirmaste.';
        }

        return $locale === 'PT'
            ? 'Registramos o valor que você informou.'
            : 'Registramos el valor que ingresaste.';
    }
}
