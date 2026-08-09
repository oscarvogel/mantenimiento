<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use DateTimeImmutable;

final class SystemEquipmentAttachmentClock implements EquipmentAttachmentClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
