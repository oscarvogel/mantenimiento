<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentQrRenderer;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

final class EndroidEquipmentQrRenderer implements EquipmentQrRenderer
{
    public function renderSvg(string $value): string
    {
        $qrCode = QrCode::create($value)->setSize(360)->setMargin(16);

        return (new SvgWriter())->write($qrCode)->getString();
    }
}
