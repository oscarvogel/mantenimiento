<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

/**
 * Toma la instantánea de cada fuente vinculada.
 *
 * Existe como puerto para que el caso de uso que coordina el refresco manual
 * no dependa de una clase concreta: la implementación real es
 * `RecordTelemetrySnapshots`.
 */
interface TelemetryRefresher
{
    /** @return array{integraciones:int, instantaneas:int, ausentes:int, falhas:int} */
    public function execute(): array;
}