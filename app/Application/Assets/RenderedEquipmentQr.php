<?php

declare(strict_types=1);

namespace App\Application\Assets;

final readonly class RenderedEquipmentQr
{
    public function __construct(
        public int $equipmentId,
        public string $equipmentCode,
        public string $targetUrl,
        public string $svg,
    ) {
    }
}
