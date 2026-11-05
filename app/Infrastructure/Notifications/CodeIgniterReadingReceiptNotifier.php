<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Measurement\Port\ReadingReceiptNotifier;
use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Application\Notifications\ReadingReceiptMessageBuilder;
use App\Domain\Measurement\AiDiscrepancyPolicy;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use Throwable;

final readonly class CodeIgniterReadingReceiptNotifier implements ReadingReceiptNotifier
{
    private const EVENT_TYPE = 'equipo.lectura_registrada';
    private const DELIVERY_KEY_PREFIX = 'lectura.registrada';

    private const ACTIVE_DRIVER_SQL = <<<'SQL'
        (
            SELECT a.empresa_id, a.equipo_id, a.empleado_id
            FROM employee_equipment_assignments a
            WHERE a.rol = 'CHOFER'
              AND a.fecha_hasta IS NULL
              AND a.id = (
                  SELECT a2.id
                  FROM employee_equipment_assignments a2
                  WHERE a2.empresa_id = a.empresa_id
                    AND a2.equipo_id = a.equipo_id
                    AND a2.rol = 'CHOFER'
                    AND a2.fecha_hasta IS NULL
                  ORDER BY a2.fecha_desde DESC, a2.id DESC
                  LIMIT 1
              )
        ) drv
        SQL;

    public function __construct(
        private BaseConnection $database,
        private WhatsAppNotificationGateway $gateway,
        private GlobalNotificationSettingsStore $settings,
        private NotificationClock $clock,
    ) {
    }

    public function notify(int $companyId, int $equipmentId, int $readingId): void
    {
        if ($companyId <= 0 || $equipmentId <= 0 || $readingId <= 0) {
            return;
        }
        if (! $this->gateway->available()) {
            log_message('info', 'Acuse de lectura omitido: el canal WhatsApp no está disponible.');

            return;
        }

        $reading = $this->readingContext($companyId, $equipmentId, $readingId);
        if ($reading === null) {
            log_message('info', 'Acuse de lectura omitido: la lectura no existe o no pertenece al equipo indicado.', [
                'reading' => $readingId,
                'company' => $companyId,
                'equipment' => $equipmentId,
            ]);

            return;
        }
        if ((int) ($reading['empresa_estado'] ?? 0) !== 1 || ($reading['empresa_deleted_at'] ?? null) !== null) {
            return;
        }
        if ((int) ($reading['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            return;
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        $globalInstanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));

        $driver = $this->currentDriver($companyId, $equipmentId);
        $employeeId = (int) ($driver['empleado_id'] ?? 0);
        $realPhone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        $destination = $pilotEnabled ? $pilotPhone : $realPhone;

        $instanceId = trim((string) ($reading['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $instanceId = $globalInstanceId;
        }

        $locale = $this->locale($reading);
        $companyName = trim((string) ($reading['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($reading['razon_social'] ?? ''));
        }

        $driverName = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $equipmentLabel = trim((string) ($reading['codigo'] ?? ''));
        $plate = trim((string) ($reading['patente'] ?? ''));
        if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
            $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
        }

        $registeredAt = $this->registeredAt($reading);
        $kilometers = $reading['kilometraje'] === null ? null : (int) $reading['kilometraje'];
        $hours = $reading['horometro'] === null ? null : (float) $reading['horometro'];
        $method = strtoupper(trim((string) ($reading['metodo_carga'] ?? '')));
        if ($method === '') {
            $method = ReadingReceiptMessageBuilder::METHOD_MANUAL;
        }
        $detectedKm = isset($reading['km_detectado_ia']) && $reading['km_detectado_ia'] !== null
            ? (int) $reading['km_detectado_ia']
            : null;
        $confidence = isset($reading['confianza_ia']) && $reading['confianza_ia'] !== null
            ? (float) $reading['confianza_ia']
            : null;

        $needsReview = (new AiDiscrepancyPolicy())->needsReview($detectedKm, $kilometers, $confidence);

        $message = (new ReadingReceiptMessageBuilder())->build(
            $locale,
            $companyName,
            $driverName,
            $equipmentLabel,
            $kilometers,
            $hours,
            $method,
            $detectedKm,
            $needsReview,
            $registeredAt,
            $readingId,
            $pilotEnabled,
            $realPhone !== null,
        );

        $deliveryKey = self::DELIVERY_KEY_PREFIX
            . ':empresa:' . $companyId
            . ':equipo:' . $equipmentId
            . ':lectura:' . $readingId;

        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        $omittedReason = null;
        if ($employeeId <= 0) {
            $omittedReason = 'El equipo no tiene un chofer vigente asignado.';
        } elseif ($destination === null) {
            $omittedReason = $pilotEnabled
                ? 'El modo piloto está activo pero no hay un teléfono piloto válido configurado.'
                : 'El chofer asignado no tiene un celular válido para WhatsApp.';
        }

        $this->database->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId > 0 ? $employeeId : null,
            'tipo_evento' => self::EVENT_TYPE,
            'clave_entrega' => $deliveryKey,
            'external_ref' => 'mantenimiento:' . $deliveryKey,
            'telefono' => $destination,
            'instance_id' => $instanceId,
            'mensaje' => $message,
            'estado' => $omittedReason === null ? 'PENDIENTE' : 'OMITIDA',
            'ultimo_error' => $omittedReason,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function readingContext(int $companyId, int $equipmentId, int $readingId): ?array
    {
        $builder = $this->database->table('lecturas_equipo le')
            ->select('le.id, le.empresa_id, le.equipo_id, le.sucursal_id, le.fecha_lectura, le.kilometraje, le.horometro')
            ->select('e.codigo, e.patente')
            ->select('co.razon_social, co.nombre_fantasia, co.notificaciones_whatsapp_habilitadas')
            ->select('co.whatsapp_instance_id, co.idioma_notificaciones')
            ->select('co.estado empresa_estado, co.deleted_at empresa_deleted_at')
            ->select('s.idioma_notificaciones sucursal_idioma_notificaciones')
            ->join('equipos e', 'e.id = le.equipo_id AND e.empresa_id = le.empresa_id', 'inner')
            ->join('empresas co', 'co.id = le.empresa_id', 'inner')
            ->join('sucursales s', 's.id = le.sucursal_id AND s.empresa_id = le.empresa_id', 'left')
            ->where('le.id', $readingId)
            ->where('le.empresa_id', $companyId)
            ->where('le.equipo_id', $equipmentId)
            ->where('le.anulada', 0)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null);

        if ($this->database->tableExists('lecturas_equipo_evidencias')) {
            $builder->select('ev.metodo_carga, ev.km_detectado_ia, ev.confianza_ia')
                ->join('lecturas_equipo_evidencias ev', 'ev.lectura_id = le.id AND ev.empresa_id = le.empresa_id', 'left');
        }

        $row = $builder->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function currentDriver(int $companyId, int $equipmentId): ?array
    {
        $row = $this->database->table('equipos e')
            ->select('drv.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->join(self::ACTIVE_DRIVER_SQL, 'drv.equipo_id = e.id AND drv.empresa_id = e.empresa_id', 'left', false)
            ->join('empleados emp', 'emp.id = drv.empleado_id AND emp.empresa_id = drv.empresa_id AND emp.activo = 1 AND emp.deleted_at IS NULL', 'left')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string,mixed> $reading */
    private function locale(array $reading): string
    {
        $branchLocale = trim((string) ($reading['sucursal_idioma_notificaciones'] ?? ''));
        $locale = $branchLocale !== '' ? $branchLocale : (string) ($reading['idioma_notificaciones'] ?? 'ES');

        return strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
    }

    /** @param array<string,mixed> $reading */
    private function registeredAt(array $reading): DateTimeImmutable
    {
        $raw = trim((string) ($reading['fecha_lectura'] ?? ''));
        if ($raw !== '') {
            try {
                return new DateTimeImmutable($raw);
            } catch (Throwable) {
            }
        }

        return $this->clock->now();
    }
}
