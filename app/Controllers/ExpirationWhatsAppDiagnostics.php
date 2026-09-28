<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class ExpirationWhatsAppDiagnostics extends BaseController
{
    public function run(): ResponseInterface
    {
        if (ENVIRONMENT === 'production') {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'error' => 'not_found']);
        }

        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'error' => 'forbidden']);
        }

        $db = db_connect();
        $companyId = max(0, (int) $this->request->getGet('empresa_id'));
        $equipmentId = max(0, (int) $this->request->getGet('equipo_id'));
        if ($companyId <= 0 || $equipmentId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'status' => 'error',
                'message' => 'Indicá empresa_id y equipo_id del móvil que querés probar.',
            ]);
        }

        $driver = $db->table('employee_equipment_assignments a')
            ->select('a.empleado_id, emp.nombre, emp.apellido')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)->where('emp.deleted_at', null)
            ->orderBy('a.id', 'DESC')->get()->getRowArray();
        if ($driver === null) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'El móvil no tiene chofer activo asignado.']);
        }

        $company = $db->table('empresas')->select('nombre_fantasia, razon_social')->where('id', $companyId)->get()->getRowArray();
        $equipment = $db->table('equipos')->select('codigo, patente')->where('empresa_id', $companyId)->where('id', $equipmentId)->get()->getRowArray();
        if ($company === null || $equipment === null) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Empresa o móvil inexistente.']);
        }

        $settings = service('globalNotificationSettings')->get();
        $gateway = service('whatsAppNotificationGateway');
        $pilotPhone = $gateway->normalizePhone((string) ($settings['whatsapp_pilot_phone'] ?? ''));
        if ($pilotPhone === null) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'No hay teléfono piloto WhatsApp válido configurado.']);
        }

        $instanceId = trim((string) ($db->table('empresas')->select('whatsapp_instance_id')->where('id', $companyId)->get()->getRow('whatsapp_instance_id') ?? ''));
        if ($instanceId === '') {
            $instanceId = trim((string) ($settings['whatsapp_instance_id'] ?? 'default'));
        }
        $companyName = trim((string) ($company['nombre_fantasia'] ?? '')) ?: trim((string) ($company['razon_social'] ?? 'Empresa'));
        $driverName = trim((string) ($driver['nombre'] ?? '') . ' ' . (string) ($driver['apellido'] ?? ''));
        $equipmentLabel = trim((string) ($equipment['codigo'] ?? ''));
        $plate = trim((string) ($equipment['patente'] ?? ''));
        if ($plate !== '' && mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)) {
            $equipmentLabel .= ($equipmentLabel === '' ? '' : ' · ') . $plate;
        }

        $testKey = 'diagnostico_414:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
        $message = "🧪 *PRUEBA CONTROLADA HITO #414*\n"
            . "*Destinatario previsto:* " . ($driverName ?: 'Chofer asignado') . "\n\n"
            . "*" . $companyName . " · Mantenimiento*\n\n"
            . "Hola " . trim((string) ($driver['nombre'] ?? '')) . " 👋\n"
            . "Tenés vencimientos para revisar:\n\n"
            . "🚛 *" . ($equipmentLabel ?: 'Equipo #' . $equipmentId) . "*\n"
            . "• PRUEBA AGRUPADA A — vence hoy (vence hoy)\n"
            . "• PRUEBA AGRUPADA B — vence hoy (vence hoy)\n\n"
            . "Por favor, coordiná la regularización con el responsable de mantenimiento.\n\n"
            . "_Sistema de mantenimiento desarrollado por Vogel Consultoría._";

        try {
            $result = $gateway->send($pilotPhone, $message, 'mantenimiento:' . $testKey, $instanceId);
        } catch (Throwable $exception) {
            log_message('error', 'Diagnóstico Hito 414 falló: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(502)->setJSON(['status' => 'error', 'hito' => 414, 'message' => 'No se pudo enviar la prueba piloto.']);
        }

        return $this->response->setJSON([
            'status' => 'ok', 'hito' => 414, 'company_id' => $companyId, 'equipment_id' => $equipmentId,
            'driver_id' => (int) $driver['empleado_id'], 'pilot_phone' => $pilotPhone,
            'test_key' => $testKey, 'gateway' => $result, 'message' => 'HITO_414=ENVIADO. Debe llegar UN WhatsApp con DOS vencimientos agrupados.',
        ]);
    }
}
