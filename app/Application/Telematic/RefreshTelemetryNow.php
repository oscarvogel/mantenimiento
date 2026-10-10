<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotifiableEventPublisher;
use App\Application\Telematic\Port\TelemetryEvaluator;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetryRefresher;
use App\Application\Telematic\Port\TelemetryRefreshGuard;
use App\Domain\Exceptions\TelemetriaNoDisponible;
use DateTimeImmutable;
use DomainException;

/**
 * Refresca la telemetría de la flota a pedido.
 *
 * Existe porque el proveedor no se puede consultar solo: los límites de
 * Wialon son por IP, no por token, y 10 intentos fallidos por minuto
 * bloquean la IP completa. Un botón con enfriamiento hace que la consulta sea
 * siempre deliberada y también puede correr como tarea programada dentro del
 * ciclo protegido del cron en producción.
 *
 * Es de sólo lectura contra el proveedor y no toca lecturas, equipos ni
 * planes: sólo refresca la última instantánea conocida y publica alertas.
 */
final readonly class RefreshTelemetryNow
{
    /** Ventana de enfriamiento: impide que un doble clic dispare dos consultas. */
    public const COOLDOWN_SEGUNDOS = 60;

    public function __construct(
        private TelemetryIntegrationCatalog $integrations,
        private TelemetryRefresher $refresher,
        private TelemetryEvaluator $evaluator,
        private NotifiableEventPublisher $publisher,
        private TelemetryRefreshGuard $guard,
        private NotificationClock $clock,
        private int $companyId,
        private int $cooldownSeconds = self::COOLDOWN_SEGUNDOS,
    ) {
    }

    /** @return TelemetryRefreshResult */
    public function execute(): TelemetryRefreshResult
    {
        $integrations = $this->integrations->activeFor($this->companyId);

        if ($integrations === []) {
            throw new TelemetriaNoDisponible('No hay ninguna integración de telemetría activa en esta empresa.');
        }

        $desde = $this->clock->now()->modify('-' . $this->cooldownSeconds . ' seconds');

        foreach ($integrations as $integracion) {
            $enfriamiento = $this->guard->ultimaLecturaDe($integracion->id());
            if ($enfriamiento !== null && $enfriamiento > $desde) {
                $restan = $enfriamiento->getTimestamp() - $this->clock->now()->getTimestamp() + $this->cooldownSeconds;

                throw new DomainException(sprintf(
                    'La telemetría se actualizó hace instantes. Probá de nuevo en %d segundos.',
                    max(1, $restan),
                ));
            }
        }

        $ingesta = $this->refresher->execute();
        $eventos = $this->evaluator->execute();

        $creadas = 0;
        $duplicadas = 0;
        foreach ($eventos as $evento) {
            $resultado = $this->publisher->execute($evento);
            $creadas += $resultado['created'];
            $duplicadas += $resultado['duplicates'];
        }

        return new TelemetryRefreshResult(
            $ingesta['integraciones'],
            $ingesta['instantaneas'],
            count($eventos),
            $creadas,
            $duplicadas,
            $ingesta['fallas'],
        );
    }
}
