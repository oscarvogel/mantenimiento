<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

final class WhatsAppPhone
{
    public static function normalize(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{9,14}$/', $raw) !== 1) {
            return null;
        }

        if (str_starts_with($raw, '54')) {
            return preg_match('/^549[0-9]{10}$/', $raw) === 1 ? $raw : null;
        }
        if (str_starts_with($raw, '55')) {
            return preg_match('/^55[0-9]{10,11}$/', $raw) === 1 ? $raw : null;
        }
        if (str_starts_with($raw, '56')) {
            return preg_match('/^56[0-9]{9}$/', $raw) === 1 ? $raw : null;
        }

        return $raw;
    }
}
