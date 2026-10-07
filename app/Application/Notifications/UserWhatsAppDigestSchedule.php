<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use DateTimeImmutable;

final class UserWhatsAppDigestSchedule
{
    public function next(DateTimeImmutable $now, string $runTime = '08:00'): DateTimeImmutable
    {
        [$hour, $minute] = $this->timeParts($runTime);
        $candidate = $now->setTime($hour, $minute);

        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute);
        }

        while ((int) $candidate->format('N') >= 6) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute);
        }

        return $candidate;
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
