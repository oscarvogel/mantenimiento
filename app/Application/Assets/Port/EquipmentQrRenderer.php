<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentQrRenderer
{
    public function renderSvg(string $value): string;
}
