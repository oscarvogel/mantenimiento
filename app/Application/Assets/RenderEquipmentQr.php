<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\EquipmentQrRenderer;
use App\Application\Identity\ActorContext;

final class RenderEquipmentQr
{
    public function __construct(
        private readonly GetEquipmentQrPayload $payloads,
        private readonly EquipmentQrRenderer $renderer,
    ) {
    }

    public function execute(ActorContext $actor, int $equipmentId, string $publicBaseUrl): RenderedEquipmentQr
    {
        $payload = $this->payloads->execute($actor, $equipmentId);
        $targetUrl = rtrim($publicBaseUrl, '/') . '/' . ltrim($payload->relativePath, '/');

        return new RenderedEquipmentQr(
            $payload->equipmentId,
            $payload->code,
            $targetUrl,
            $this->renderer->renderSvg($targetUrl),
        );
    }
}
