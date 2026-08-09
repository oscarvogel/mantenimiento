<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use DateTimeImmutable;

interface EquipmentAttachmentClock
{
    public function now(): DateTimeImmutable;
}
