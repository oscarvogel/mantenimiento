<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Infrastructure\PublicEquipmentAccess\CodeIgniterPublicEquipmentTokenRepository;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

/**
 * Reclama por WhatsApp al chofer de un equipo con lectura de km atrasada.
 *
 * Es una extensión de la pantalla de control de lecturas ya validada: no crea
 * un segundo mecanismo de envío. Reutiliza la infraestructura existente:
 *
 *  - `WhatsAppNotificationGateway` (VogelWhatsAppApiGateway) para enviar;
 *  - `notificacion_whatsapp_entregas` para trazar, con las columnas que
 *    EXISTEN: tipo_evento, clave_entrega, telefono, instance_id, mensaje,
 *    estado, gateway_message_id. NO se inventan `created_by` ni `deleted_at`
 *    porque esas columnas no existen en el esquema;
 *  - `GlobalNotificationSettingsStore` para la configuración y el piloto;
 *  - `CodeIgniterPublicEquipmentTokenRepository` para el enlace público seguro
 *    de carga de lecturas ya existente en el sistema.
 *
 * El destinatario SIEMPRE se resuelve en el servidor a partir del equipo
 * indicado: empresa -> equipo -> asignacion vigente -> chofer -> telefono. El
 * navegador solo envia `equipmentId`; jamás un numero.
 *
 * Trazabilidad: la tabla de entregas registra empresa, equipo, empleado,
 * telefono, mensaje, estado y timestamps, pero NO quien opero la accion. No
 * existe columna para el usuario iniciador y no se crea una migration en este
 * hotfix. La limitacion queda documentada en el reporte.
 */
final class ClaimReadingReminder
{
    /**
     * Tipo de evento propio del reclamo manual. Es una constante y no un
     * permiso: el permiso vive en el filtro de ruta.
     */
    public const EVENT_TYPE = 'equipo.reclamo_manual_lectura';

    /**
     * Ventana de guarda contra el doble clic. Un reclamo al mismo chofer y
     * equipo dentro de este intervalo no genera un segundo envío.
     */
    private const DOUBLE_SUBMIT_GUARD_MINUTES = 10;

    /**
     * Ultima lectura vigente por equipo: mismo criterio determinista que usa
     * `ListReadingControl` (fecha desc, id desc), para que el mensaje muestre
     * el kilometraje de esa misma fila.
     */
    private const LAST_READING_SQL = <<<'SQL'
        (
            SELECT le.empresa_id, le.equipo_id, le.fecha_lectura, le.kilometraje
            FROM lecturas_equipo le
            WHERE le.anulada = 0
              AND le.id = (
                  SELECT l2.id
                  FROM lecturas_equipo l2
                  WHERE l2.empresa_id = le.empresa_id
                    AND l2.equipo_id = le.equipo_id
                    AND l2.anulada = 0
                  ORDER BY l2.fecha_lectura DESC, l2.id DESC
                  LIMIT 1
              )
        ) lr
        SQL;

    /**
     * Asignacion de chofer vigente: una sola fila por equipo.
     */
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
        private readonly BaseConnection $database,
        private readonly WhatsAppNotificationGateway $gateway,
        private readonly WhatsAppNotificationDeliveryQueue $queue,
        private readonly GlobalNotificationSettingsStore $settings,
        private readonly NotificationClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, int $equipmentId): ClaimReadingResult
    {
        if ($equipmentId <= 0) {
            throw new DomainException('No se indicó un equipo válido para reclamar.');
        }

        $companyId = $actor->companyId();

        // --- Empresa y equipo, con aislamiento multiempresa obligatorio -----
        $company = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia, notificaciones_whatsapp_habilitadas, whatsapp_instance_id, idioma_notificaciones')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($company === null) {
            throw new DomainException('La empresa no está activa o no existe.');
        }
        if ((int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            throw new DomainException('Las notificaciones por WhatsApp no están habilitadas para esta empresa.');
        }

        $equipment = $this->database->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.empresa_id')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id AND te.activo = 1', 'inner')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->get()
            ->getRowArray();

        if ($equipment === null) {
            throw new DomainException('El equipo no existe, no pertenece a esta empresa o no controla kilometraje.');
        }

        // --- Destinatario: lo resuelve el servidor, nunca el navegador ------
        $driver = $this->database->table('equipos e')
            ->select('drv.empleado_id, emp.nombre, emp.apellido, emp.telefono, s.idioma_notificaciones')
            ->join(self::ACTIVE_DRIVER_SQL, 'drv.equipo_id = e.id AND drv.empresa_id = e.empresa_id', 'left', false)
            ->join('empleados emp', 'emp.id = drv.empleado_id AND emp.empresa_id = drv.empresa_id AND emp.activo = 1 AND emp.deleted_at IS NULL', 'left')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'left')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->get()
            ->getRowArray();

        $employeeId = (int) ($driver['empleado_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new DomainException('El equipo no tiene un chofer activo asignado.');
        }

        $realPhone = $this->gateway->normalizePhone((string) ($driver['telefono'] ?? ''));
        if ($realPhone === null) {
            throw new DomainException('El chofer asignado no tiene un celular válido cargado para WhatsApp.');
        }

        // --- Configuracion y piloto ----------------------------------------
        if (! $this->gateway->available()) {
            throw new DomainException('El canal WhatsApp no está configurado o está deshabilitado.');
        }

        $settings = $this->settings->get();
        $pilotEnabled = (bool) ($settings['whatsapp_pilot_enabled'] ?? true);
        $pilotPhone = $this->gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        if ($pilotEnabled && $pilotPhone === null) {
            throw new DomainException('El modo piloto está activo pero no hay un teléfono piloto válido configurado.');
        }

        $destination = $pilotEnabled ? $pilotPhone : $realPhone;
        if ($destination === null) {
            throw new DomainException('No hay un destinatario válido para enviar el reclamo.');
        }

        $instanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
        if ($instanceId === '') {
            $instanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        }
        if ($instanceId === '') {
            throw new DomainException('No se definió una instancia de WhatsApp para el envío.');
        }

        // --- Ultima lectura vigente, para el mensaje -----------------------
        $lastReading = $this->lastReading($companyId, $equipmentId);

        $now = $this->clock->now();
        $timestamp = $now->format('Y-m-d H:i:s');

        // --- Elegibilidad: solo lectura pendiente/atrasada -------------------
        // Mismo criterio que habilita el botón (`needsClaim` / GT_3 "Más de
        // 3 días", que ya incluye SIN_LECTURA). Un POST manual para un equipo
        // con lectura de hoy o al día se rechaza acá: no se confía en Vue.
        $this->assertClaimNeeded($lastReading['fecha_lectura'], $now);

        // --- Enlace publico de carga, ya existente en el sistema ------------
        $publicUrl = $this->publicReadingUrl($companyId, $equipmentId);

        // --- Guarda contra doble clic / repeticion inmediata ---------------
        $recent = $this->recentClaimDelivery($companyId, $equipmentId, $employeeId, $now);
        if ($recent !== null) {
            return ClaimReadingResult::blockedByRecentClaim(
                'Ya se reclamó a este chofer por este equipo hace menos de '
                . self::DOUBLE_SUBMIT_GUARD_MINUTES . ' minutos.',
                $recent,
            );
        }

        $companyName = trim((string) ($company['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($company['razon_social'] ?? ''));
        }

        $driverName = trim((string) ($driver['nombre'] ?? ''));
        $fullName = trim($driverName . ' ' . (string) ($driver['apellido'] ?? ''));

        $message = $this->claimMessage(
            $fullName,
            (string) ($equipment['codigo'] ?? ''),
            trim((string) ($equipment['patente'] ?? '')),
            $lastReading,
            $publicUrl,
            $companyName,
            $pilotEnabled,
            $realPhone !== null,
        );

        $deliveryKey = self::EVENT_TYPE
            . ':empresa:' . $companyId
            . ':equipo:' . $equipmentId
            . ':chofer:' . $employeeId
            . ':ventana:' . $now->format('YmdHi');

        // `ignore(true)` + indice unico de clave_entrega: la segunda peticion
        // concurrente no inserta una segunda fila ni duplica el envio.
        $this->database->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId,
            'tipo_evento' => self::EVENT_TYPE,
            'clave_entrega' => $deliveryKey,
            'external_ref' => 'mantenimiento:' . $deliveryKey,
            'telefono' => $destination,
            'instance_id' => $instanceId,
            'mensaje' => $message,
            'estado' => 'PENDIENTE',
            'ultimo_error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $delivery = $this->database->table('notificacion_whatsapp_entregas')
            ->select('id')
            ->where('clave_entrega', $deliveryKey)
            ->get()
            ->getRowArray();

        $deliveryId = (int) ($delivery['id'] ?? 0);
        if ($deliveryId <= 0) {
            throw new DomainException('No se pudo registrar el reclamo antes de enviarlo.');
        }

        try {
            $result = $this->gateway->sendText(
                $destination,
                $message,
                'mantenimiento:' . $deliveryKey,
                (string) $actor->userId(),
                $actor->isSuperAdmin() ? 'Superadmin Mantenimiento' : 'Mantenimiento',
                $instanceId,
            );
        } catch (Throwable $exception) {
            $this->markFailed($deliveryId, $exception->getMessage(), $now);
            throw new DomainException(
                'No se pudo enviar el reclamo por WhatsApp: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $this->queue->accepted($deliveryId, (string) $result['messageId'], (string) $result['status']);

        return ClaimReadingResult::sent(
            $deliveryId,
            $destination,
            (string) $result['messageId'],
            $pilotEnabled,
            $publicUrl,
        );
    }

    /**
     * Rechaza el reclamo cuando la última lectura está vigente.
     *
     * `null` significa SIN_LECTURA y siempre habilita el reclamo. Cualquier
     * otra fecha debe cumplir el mismo criterio GT_3 que habilita el botón;
     * HOY y AL_DIA se rechazan con un mensaje que distingue ambos casos.
     */
    private function assertClaimNeeded(?string $lastReadingAt, \DateTimeImmutable $now): void
    {
        if ($lastReadingAt === null) {
            return;
        }

        try {
            $lastReadingDate = new \DateTimeImmutable($lastReadingAt);
        } catch (\Throwable) {
            $lastReadingDate = null;
        }

        if ($lastReadingDate === null) {
            throw new DomainException('No se pudo verificar la antigüedad de la última lectura.');
        }

        $staleFilter = ReadingControlFilter::fromKey(ReadingControlFilter::GT_3, $now);
        if ($staleFilter->matches($lastReadingDate)) {
            return;
        }

        $todayFilter = ReadingControlFilter::fromKey(ReadingControlFilter::TODAY, $now);

        throw new DomainException($todayFilter->matches($lastReadingDate)
            ? 'El equipo ya tiene una lectura cargada hoy; no corresponde reclamar.'
            : 'El equipo tiene su lectura al día; no corresponde reclamar.');
    }

    /**
     * Última lectura vigente del equipo: fecha y kilometraje de la MISMA fila.
     *
     * @return array{fecha_lectura: string|null, kilometraje: int|null}
     */
    private function lastReading(int $companyId, int $equipmentId): array
    {
        $row = $this->database->table('lecturas_equipo le')
            ->select('le.fecha_lectura, le.kilometraje')
            ->where('le.empresa_id', $companyId)
            ->where('le.equipo_id', $equipmentId)
            ->where('le.anulada', 0)
            ->orderBy('le.fecha_lectura', 'DESC')
            ->orderBy('le.id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return [
            'fecha_lectura' => $row === null ? null : ($row['fecha_lectura'] === null ? null : (string) $row['fecha_lectura']),
            'kilometraje' => ($row === null || $row['kilometraje'] === null) ? null : (int) $row['kilometraje'],
        ];
    }

    /**
     * Enlace público de carga ya existente, con token verificado contra el
     * mismo equipo. Nunca se expone un id interno.
     */
    private function publicReadingUrl(int $companyId, int $equipmentId): string
    {
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        $repository = new CodeIgniterPublicEquipmentTokenRepository($this->database);

        $token = $repository->ensureActivePlainTokenForEquipment($companyId, $equipmentId, $timestamp);
        if (! is_string($token) || trim($token) === '') {
            throw new DomainException('No se pudo generar el enlace público de carga para este equipo.');
        }

        $resolved = $repository->resolveActiveToken(hash('sha256', $token));
        if ($resolved === null
            || (int) ($resolved['empresa_id'] ?? 0) !== $companyId
            || (int) ($resolved['equipo_id'] ?? 0) !== $equipmentId) {
            throw new DomainException('El enlace público no corresponde al equipo solicitado.');
        }

        return base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');
    }

    private function recentClaimDelivery(int $companyId, int $equipmentId, int $employeeId, \DateTimeInterface $now): ?int
    {
        $since = (new \DateTimeImmutable('@' . $now->getTimestamp()))
            ->modify('-' . self::DOUBLE_SUBMIT_GUARD_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $row = $this->database->table('notificacion_whatsapp_entregas')
            ->select('id')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('empleado_id', $employeeId)
            ->where('tipo_evento', self::EVENT_TYPE)
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO', 'PENDIENTE_CONFIRMACION', 'ACEPTADA'])
            ->where('created_at >=', $since)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row === null ? null : (int) ($row['id'] ?? 0);
    }

    private function markFailed(int $deliveryId, string $error, \DateTimeInterface $now): void
    {
        $this->database->table('notificacion_whatsapp_entregas')->where('id', $deliveryId)->update([
            'estado' => 'ERROR',
            'ultimo_error' => mb_substr($error, 0, 1000),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array{fecha_lectura: string|null, kilometraje: int|null} $lastReading
     */
    private function claimMessage(
        string $driverFullName,
        string $equipmentCode,
        string $equipmentPlate,
        array $lastReading,
        string $publicUrl,
        string $companyName,
        bool $pilotEnabled,
        bool $realPhoneValid,
    ): string {
        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . "*Destinatario previsto:* " . ($driverFullName === '' ? 'Chofer asignado' : $driverFullName) . "\n"
                . "*Teléfono real:* " . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n"
            : '';

        $label = trim($equipmentCode);
        if ($equipmentPlate !== '' && mb_strtoupper($equipmentPlate) !== mb_strtoupper($label)) {
            $label .= ($label === '' ? '' : ' · ') . $equipmentPlate;
        }
        if ($label === '') {
            $label = 'el equipo';
        }

        $firstName = trim(explode(' ', $driverFullName)[0] ?? '');
        $greeting = $firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋';

        if ($lastReading['fecha_lectura'] === null) {
            $readingBlock = "Todavía no tenemos una lectura registrada para este equipo.";
        } else {
            $km = $lastReading['kilometraje'] === null
                ? null
                : number_format((float) $lastReading['kilometraje'], 0, ',', '.');
            $date = $this->formatReadingDate($lastReading['fecha_lectura']);
            $readingBlock = ($km === null ? '' : $km . ' km') . ($km === null ? '' : ' — ') . $date;
        }

        return $pilotHeader
            . '*' . ($companyName !== '' ? $companyName : 'Empresa') . '* · Mantenimiento' . "\n\n"
            . $greeting . "\n\n"
            . 'Te recordamos registrar el kilometraje actualizado del equipo *' . $label . '*.' . "\n\n"
            . '*Última lectura registrada:*' . "\n"
            . $readingBlock . "\n\n"
            . "*Hacé esto:*\n"
            . "1️⃣ Tocá el enlace de abajo.\n"
            . "2️⃣ Mirá el tablero del vehículo y escribí el número que marca.\n"
            . "3️⃣ Tocá *Registrar lectura*.\n\n"
            . '👉 *ABRIR PARA CARGAR LOS KM:*' . "\n"
            . $publicUrl . "\n\n"
            . 'Cuando aparezca *“Lectura registrada”*, ya terminaste y podés cerrar la pantalla. ✅' . "\n\n"
            . '*No hace falta responder este WhatsApp.*';
    }

    private function formatReadingDate(string $value): string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (Throwable) {
            return 'sin fecha disponible';
        }

        return $date->format('d/m/Y H:i');
    }
}
