<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use DateTimeImmutable;

final class UserWhatsAppDigestSchedule
{
    public function slot(DateTimeImmutable $now, string $runTime = '08:00'): ?DateTimeImmutable
    {
        if ((int) $now->format('N') >= 6) {
            return null;
        }

        [$hour, $minute] = $this->timeParts($runTime);

        return $now->setTime($hour, $minute);
    }

    /** @return array{0:int,1:int} */
    private function timeParts(string $runTime): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($runTime), $matches) !== 1) {
            return [8, 0];
        }

        return [
            max(0, min(23, (int) $matches[1])),
            max(0, min(59, (int) $matches[2])),
        ];
    }
}
