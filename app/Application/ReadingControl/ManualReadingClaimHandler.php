<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\PublicEquipmentAccess\Port\PublicEquipmentTokenRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;

final class ManualReadingClaimHandler
{
    public function __construct(
        private readonly BaseConnection $database,
        private readonly WhatsAppNotificationDeliveryQueue $queue,
        private readonly NotificationClock $clock,
        private readonly PublicEquipmentTokenRepository $tokenRepository,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     deliveryId: int|null,
     *     phone: string|null,
     *     instanceId: string|null,
     * }
     */
    public function execute(
        ActorContext $actor,
        int $equipmentId,
    ): array {
        $companyId = $actor->companyId();
        $userId = $actor->userId();

        // 1. Validate equipment belongs to company and controls km
        $equipment = $this->database->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.empresa_id, e.sucursal_id, te.controla_km')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->where('te.activo', 1)
            ->get()
            ->getRowArray();

        if ($equipment === null) {
            return [
                'success' => false,
                'message' => 'El móvil no existe, no está activo o no controla kilometraje.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        // 2. Get current driver assignment
        $driver = $this->database->table('employee_equipment_assignments a')
            ->select('a.empleado_id, emp.nombre, emp.apellido, emp.telefono')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($driver === null) {
            return [
                'success' => false,
                'message' => 'El móvil no tiene un chofer activo asignado.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        $employeeId = (int) $driver['empleado_id'];
        $driverName = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        if ($driverName === '') {
            $driverName = 'Chofer #' . $employeeId;
        }

        // 3. Validate phone
        $gateway = $this->queue; // WhatsAppNotificationDeliveryQueue has gateway
        $phone = $this->normalizePhone((string) ($driver['telefono'] ?? ''));
        if ($phone === null) {
            return [
                'success' => false,
                'message' => 'El chofer asignado no tiene un celular válido para WhatsApp.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        // 4. Get/ensure public token for the equipment
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        $token = $this->tokenRepository->ensureActivePlainTokenForEquipment($companyId, $equipmentId, $timestamp);
        if (! is_string($token) || trim($token) === '') {
            return [
                'success' => false,
                'message' => 'No se pudo generar el enlace público para cargar kilómetros.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        // Validate token resolves correctly
        $resolved = $this->tokenRepository->resolveActiveToken(hash('sha256', $token));
        if ($resolved === null
            || (int) ($resolved['empresa_id'] ?? 0) !== $companyId
            || (int) ($resolved['equipo_id'] ?? 0) !== $equipmentId) {
            return [
                'success' => false,
                'message' => 'El enlace público generado no es válido para este equipo.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        $publicUrl = base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');

        // 5. Determine WhatsApp instance
        $company = $this->database->table('empresas')
            ->select('razon_social, nombre_fantasia, notificaciones_whatsapp_habilitadas, whatsapp_instance_id')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($company === null || (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
            return [
                'success' => false,
                'message' => 'WhatsApp no está habilitado para esta empresa.',
                'deliveryId' => null,
                'phone' => null,
                'instanceId' => null,
            ];
        }

        $companyName = trim((string) ($company['nombre_fantasia'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($company['razon_social'] ?? ''));
        }

        $instanceId = trim((string) ($company['whatsapp_instance_id'] ?? ''));
        $globalSettings = $this->queue instanceof \App\Infrastructure\Notifications\CodeIgniterWhatsAppNotificationDeliveryQueue
            ? $this->getGlobalInstanceId($this->database)
            : 'default';
        if ($instanceId === '') {
            $instanceId = $globalSettings;
        }

        // 6. Build message
        $equipmentLabel = trim((string) ($equipment['codigo'] ?? ''));
        $plate = trim((string) ($equipment['patente'] ?? ''));
        if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
            $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
        }
        if ($equipmentLabel === '') {
            $equipmentLabel = 'Móvil #' . $equipmentId;
        }

        $message = $this->buildMessage($driverName, $equipmentLabel, $publicUrl, $companyName);

        // 7. Create delivery record with idempotency key
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $deliveryKey = 'reclamo_manual_lectura:empresa:' . $companyId
            . ':equipo:' . $equipmentId
            . ':chofer:' . $employeeId
            . ':usuario:' . $userId
            . ':fecha:' . (new \DateTimeImmutable($now))->format('Ymd');

        // Check if already claimed today by same user
        $existing = $this->database->table('notificacion_whatsapp_entregas')
            ->where('clave_entrega', $deliveryKey)
            ->get()
            ->getRowArray();

        if ($existing !== null) {
            $existingStatus = (string) ($existing['estado'] ?? '');
            return [
                'success' => true,
                'message' => 'Este chofer ya fue reclamado hoy por ' . ($existing['created_by'] ?? 'un usuario') . ' a las ' . (new \DateTimeImmutable((string) $existing['created_at']))->format('H:i') . '.',
                'deliveryId' => (int) $existing['id'],
                'phone' => $phone,
                'instanceId' => $instanceId,
            ];
        }

        // 8. Enqueue WhatsApp delivery
        $this->database->table('notificacion_whatsapp_entregas')->ignore(true)->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId,
            'tipo_evento' => 'equipo.reclamo_manual_lectura',
            'clave_entrega' => $deliveryKey,
            'external_ref' => 'mantenimiento:' . $deliveryKey,
            'telefono' => $phone,
            'instance_id' => $instanceId,
            'mensaje' => $message,
            'estado' => 'PENDIENTE',
            'ultimo_error' => null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $deliveryId = (int) $this->database->insertID();
        if ($deliveryId <= 0) {
            return [
                'success' => false,
                'message' => 'No se pudo registrar el reclamo en la cola de WhatsApp.',
                'deliveryId' => null,
                'phone' => $phone,
                'instanceId' => $instanceId,
            ];
        }

        log_message('notice', 'Reclamo manual de lectura encolado: {deliveryKey}', ['deliveryKey' => $deliveryKey]);

        return [
            'success' => true,
            'message' => 'Reclamo por WhatsApp encolado correctamente.',
            'deliveryId' => $deliveryId,
            'phone' => $phone,
            'instanceId' => $instanceId,
        ];
    }

    private function buildMessage(string $driverName, string $equipmentLabel, string $publicUrl, string $companyName): string
    {
        return "*" . ($companyName !== '' ? $companyName : 'Empresa') . " · Mantenimiento*\n\n"
            . "Hola " . $driverName . " 👋\n\n"
            . "Desde Mantenimiento necesitamos que actualices el kilometraje del móvil que tenés asignado: *" . $equipmentLabel . "*.\n\n"
            . "*Hacé esto:*\n"
            . "1️⃣ Tocá el enlace de abajo.\n"
            . "2️⃣ Mirá el tablero del vehículo y escribí el número que marca.\n"
            . "3️⃣ Tocá *Registrar lectura*.\n\n"
            . "👉 *ABRIR PARA CARGAR LOS KM:*\n" . $publicUrl . "\n\n"
            . "Cuando aparezca *“Lectura registrada”*, ya terminaste y podés cerrar la pantalla. ✅\n\n"
            . "*No hace falta responder este WhatsApp.*\n\n"
            . "_Sistema de mantenimiento desarrollado por Vogel Consultoría._";
    }

    private function normalizePhone(string $phone): ?string
    {
        $clean = preg_replace('/\D+/', '', $phone);
        if ($clean === '') {
            return null;
        }

        // Argentina: convert 011XXXXXXXX or 11XXXXXXXX to 54911XXXXXXXX
        if (str_starts_with($clean, '0')) {
            $clean = substr($clean, 1);
        }
        if (strlen($clean) === 10 && str_starts_with($clean, '11')) {
            $clean = '549' . $clean;
        } elseif (strlen($clean) === 11 && str_starts_with($clean, '549')) {
            // already formatted
        } elseif (strlen($clean) === 12 && str_starts_with($clean, '5411')) {
            $clean = '549' . substr($clean, 2);
        } elseif (strlen($clean) === 13 && str_starts_with($clean, '54911')) {
            // already formatted with 9
        } else {
            // Unknown format, reject
            return null;
        }

        // Must be 13 digits for Argentina mobile with 9: 549XXXXXXXXXX
        if (strlen($clean) !== 13 || ! str_starts_with($clean, '549')) {
            return null;
        }

        return $clean;
    }

    private function getGlobalInstanceId(BaseConnection $database): string
    {
        if (! $database->tableExists('configuracion_canales_globales')) {
            return 'default';
        }
        $settings = $database->table('configuracion_canales_globales')->where('id', 1)->get()->getRowArray() ?? [];
        return trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
    }
}