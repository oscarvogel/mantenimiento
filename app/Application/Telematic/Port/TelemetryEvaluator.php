<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Notifications\NotifiableEvent;

/**
 * Evalúa la cobertura de telemetría y devuelve los eventos a publicar.
 *
 * Existe como puerto para que el caso de uso que coordina el refresco manual
 * no dependa de una clase concreta: la implementación real es
 * `DiagnoseSilentUnits`.
 */
interface TelemetryEvaluator
{
    /** @return list<NotifiableEvent> */
    public function execute(): array;
}