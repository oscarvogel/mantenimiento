<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\ManagementReadingReportSettingsReadModel;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\ReadingComplianceReadModel;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Informe automatico de equipos sin lectura para la gerencia de la empresa.
 *
 * Los choferes ya reciben recordatorios (automaticos y manuales). Cuando el
 * reclamo no alcanza, el escalamiento correcto es hacia quien puede ordenar:
 * la duena o responsable de la empresa. Este caso de uso programa ese aviso por
 * WhatsApp al telefono de gerencia configurado en la empresa.
 *
 * Reglas:
 *
 * 1. Solo informa equipos activos que controlan kilometraje y cuya ultima
 *    lectura vigente es anterior al umbral configurado (7 dias por defecto,
 *    el mismo criterio que usa el tablero).
 * 2. Si no hay equipos atrasados no se envia nada: un aviso sin contenido es
 *    ruido y entrena a la gerencia a ignorar el canal.
 * 3. Como maximo un aviso por empresa por dia, mediante clave de entrega. Si el
 *    problema sigue, la gerencia lo ve todos los dias; si se resuelve, el
 *    aviso deja de aparecer por si mismo.
 * 4. Un fallo al preparar una empresa no puede abortar el ciclo entero y dejar
 *    sin despachar el resto de las notificaciones.
 */
final readonly class ScheduleManagementReadingReport
{
    public const EVENT_TYPE = 'informe.lecturas.gerencia';

    private const MAX_ITEMS = 50;
    private const MAX_SHOWN = 10;

    public function __construct(
        private NotificationClock $clock,
        private ManagementReadingReportSettingsReadModel $settings,
        private ReadingComplianceReadModel $readings,
        private ReadingComplianceReportMessageBuilder $builder,
        private WhatsAppNotificationDeliveryQueue $queue,
        private WhatsAppNotificationGateway $gateway,
        private GlobalNotificationSettingsStore $globalSettings,
        private int $defaultThresholdDays = 7,
        private string $controlPath = '/mantenimiento/lecturas/control',
    ) {
    }

    /** @return array{companies:int,queued:int,duplicates:int,skipped:int,failed:int} */
    public function execute(bool $force = false): array
    {
        $summary = ['companies' => 0, 'queued' => 0, 'duplicates' => 0, 'skipped' => 0, 'failed' => 0];
        if (! $this->gateway->available()) {
            return $summary;
        }

        $companies = $this->settings->configuredCompanies();
        $summary['companies'] = count($companies);
        if ($companies === []) {
            return $summary;
        }

        $now = $this->clock->now();
        $settings = $this->globalSettings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));

        foreach ($companies as $company) {
            if (! ($company['enabled'] ?? false)) {
                $summary['skipped']++;
                continue;
            }

            try {
                $result = $this->queueCompany($company, $now, $force, $pilotEnabled, $pilotPhone, $globalInstanceId);
            } catch (Throwable $error) {
                $summary['failed']++;
                log_message('error', 'No se pudo programar el informe de lecturas de la empresa {company}: {message}', [
                    'company' => (int) ($company['companyId'] ?? 0),
                    'message' => $error->getMessage(),
                ]);
                continue;
            }
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Prepara el aviso de una empresa concreta, ignorando la ventana horaria.
     *
     * @param array{companyId:int,companyName:string,locale:string,phone:?string,enabled:bool,hour:string,thresholdDays:int,whatsappInstanceId:string} $company
     */
    public function queueTest(int $companyId): string
    {
        $company = null;
        foreach ($this->settings->configuredCompanies() as $candidate) {
            if ((int) ($candidate['companyId'] ?? 0) === $companyId) {
                $company = $candidate;
                break;
            }
        }

        if ($company === null) {
            throw new DomainException('No se encontró la configuración del informe de lecturas de esta empresa. Verificá que la migración esté aplicada.');
        }

        if (trim((string) ($company['phone'] ?? '')) === '') {
            throw new DomainException('La empresa no tiene un teléfono de gerencia configurado. Cargalo antes de probar el informe.');
        }

        $now = $this->clock->now();
        $settings = $this->globalSettings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));

        if ($this->resolveDestination($company, $pilotEnabled, $pilotPhone) === null) {
            throw new DomainException($pilotEnabled
                ? 'El modo piloto está activo pero no hay un teléfono piloto configurado. Configurá el teléfono piloto o desactivá el modo piloto para probar el envío real.'
                : 'El teléfono de gerencia no es un número válido para WhatsApp. Cargalo en formato internacional, solo dígitos.');
        }

        $result = $this->queueCompany($company, $now, true, $pilotEnabled, $pilotPhone, $globalInstanceId);
        if ($result !== 'queued') {
            throw new DomainException('La empresa no tiene equipos sin lectura en este momento, así que no hay nada que informar.');
        }

        return $result;
    }

    /**
     * Telefono al que sale el aviso.
     *
     * En modo piloto sale al teléfono piloto configurado, igual que el resto de
     * las notificaciones del sistema: el primer contacto real no puede ser un
     * WhatsApp al cliente sin revisar el texto.
     *
     * @param array{companyId:int,companyName:string,locale:string,phone:?string,enabled:bool,hour:string,thresholdDays:int,whatsappInstanceId:string} $company
     */
    private function resolveDestination(array $company, bool $pilotEnabled, ?string $pilotPhone): ?string
    {
        $realPhone = $this->gateway->normalizePhone((string) ($company['phone'] ?? ''));
        if ($realPhone === null) {
            return null;
        }

        return $pilotEnabled ? $pilotPhone : $realPhone;
    }

    /**
     * @param array{companyId:int,companyName:string,locale:string,phone:?string,enabled:bool,hour:string,thresholdDays:int,whatsappInstanceId:string} $company
     */
    private function queueCompany(
        array $company,
        DateTimeImmutable $now,
        bool $force,
        bool $pilotEnabled,
        ?string $pilotPhone,
        string $globalInstanceId,
    ): string {
        $companyId = (int) ($company['companyId'] ?? 0);
        if ($companyId <= 0) {
            return 'skipped';
        }

        $realPhone = $this->gateway->normalizePhone((string) ($company['phone'] ?? ''));
        if ($realPhone === null) {
            log_message('warning', 'La empresa {company} tiene el informe de lecturas habilitado pero el teléfono de gerencia no es válido.', [
                'company' => $companyId,
            ]);
            return 'skipped';
        }

        $destination = $this->resolveDestination($company, $pilotEnabled, $pilotPhone);
        if ($destination === null) {
            return 'skipped';
        }

        if (! $force && ! $this->isDue((string) ($company['hour'] ?? '08:00'), $now)) {
            return 'skipped';
        }

        $thresholdDays = max(1, (int) ($company['thresholdDays'] ?? 0) ?: $this->defaultThresholdDays);
        $cutoff = $now->setTime(0, 0)->modify('-' . $thresholdDays . ' days');
        $items = $this->readings->staleReadings($companyId, $cutoff, self::MAX_ITEMS);
        if ($items === []) {
            return 'skipped';
        }

        $prepared = [];
        foreach ($items as $item) {
            $prepared[] = [
                'code' => (string) ($item['code'] ?? ''),
                'plate' => (string) ($item['plate'] ?? ''),
                'driver' => (string) ($item['driver'] ?? ''),
                'lastReadingAt' => $item['lastReadingAt'] ?? null,
                'lastKm' => $item['lastKm'] ?? null,
                'daysSince' => $this->daysSince($item['lastReadingAt'] ?? null, $now),
            ];
        }

        $message = $this->builder->build(
            (string) ($company['locale'] ?? 'ES'),
            (string) ($company['companyName'] ?? ''),
            $prepared,
            $thresholdDays,
            $this->controlUrl($thresholdDays),
            $pilotEnabled,
            $realPhone !== null,
            'Contacto de gerencia',
            self::MAX_SHOWN,
        );

        $key = 'informe_lecturas_gerencia:empresa:' . $companyId . ':fecha:' . $now->format('Ymd')
            . ($force ? ':test:' . $now->format('YmdHis') : '');

        $instanceId = trim((string) ($company['whatsappInstanceId'] ?? ''));
        if ($instanceId === '') {
            $instanceId = $globalInstanceId;
        }

        $deliveryId = $this->queue->enqueueManagementReadingReport(
            $companyId,
            $key,
            $destination,
            $instanceId,
            $message,
            $now->format('Y-m-d H:i:s'),
        );

        return $deliveryId > 0 ? 'queued' : 'duplicates';
    }

    private function isDue(string $hour, DateTimeImmutable $now): bool
    {
        $hour = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($hour)) === 1 ? trim($hour) : '08:00';

        return $now->format('H:i') >= $hour;
    }

    private function daysSince(?string $lastReadingAt, DateTimeImmutable $now): ?int
    {
        if ($lastReadingAt === null || trim($lastReadingAt) === '') {
            return null;
        }

        try {
            // La lectura viene de la base como fecha naive, en la zona de la
            // aplicacion. Se interpreta en la zona del reloj para que la
            // comparacion no dependa de la zona por defecto de PHP.
            $last = new DateTimeImmutable($lastReadingAt, $now->getTimezone());
        } catch (Throwable) {
            return null;
        }

        $today = $now->setTime(0, 0);
        $days = (int) $last->setTime(0, 0)->diff($today)->format('%r%a');

        return max(0, $days);
    }

    /**
     * Enlace al control de lecturas con el filtro de antiguedad mas cercano al
     * umbral configurado. Los unicos filtros que la pantalla entiende son
     * "mas de 3 dias" y "mas de 7 dias".
     */
    private function controlUrl(int $thresholdDays): string
    {
        $filter = $thresholdDays <= 3 ? 'gt_3' : 'gt_7';
        $path = trim($this->controlPath) !== '' ? trim($this->controlPath) : '/mantenimiento/lecturas/control';

        return $path . '?filter=' . $filter;
    }
}
