<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\PublicEquipmentAccess\ResolvePublicEquipmentToken;
use App\Infrastructure\PublicEquipmentAccess\CodeIgniterPublicEquipmentTokenRepository;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class PublicEquipmentReadings extends BaseController
{
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW_MINUTES = 10;
    private const MAX_KM_JUMP = 5000;
    private const MAX_HOURS_JUMP = 500.0;

    public function show(string $token): string
    {
        try {
            $access = $this->resolve($token);
            $equipment = $this->equipment((int) $access['empresa_id'], (int) $access['equipo_id']);
            if ($equipment === null) {
                throw new DomainException('El acceso QR no es válido o dejó de estar vigente.');
            }

            return view('public_equipment/reading', [
                'token' => $token,
                'equipment' => $equipment,
                'requestKey' => $this->uuid(),
                'success' => session()->getFlashdata('success'),
                'error' => session()->getFlashdata('error'),
                'largeJump' => (bool) session()->getFlashdata('large_jump'),
            ]);
        } catch (Throwable $exception) {
            return view('public_equipment/invalid', [
                'message' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudo abrir el acceso QR.',
            ]);
        }
    }

    public function store(string $token): RedirectResponse
    {
        $target = base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');
        $requestKey = trim((string) $this->request->getPost('request_key'));

        try {
            $access = $this->resolve($token);
            $tokenId = (int) $access['id'];
            $database = db_connect();
            $ipHash = hash('sha256', (string) $this->request->getIPAddress());

            if ($requestKey === '' || strlen($requestKey) > 36) {
                throw new DomainException('La solicitud no es válida. Volvé a escanear el QR.');
            }

            $existing = $database->table('qr_lecturas_auditoria')
                ->where('request_key', $requestKey)
                ->get()
                ->getRowArray();
            if ($existing !== null && ($existing['resultado'] ?? '') === 'ACEPTADO') {
                return redirect()->to($target)->with('success', 'La lectura ya había sido registrada.');
            }

            $since = date('Y-m-d H:i:s', time() - (self::RATE_WINDOW_MINUTES * 60));
            $recent = $database->table('qr_lecturas_auditoria')
                ->where('token_id', $tokenId)
                ->where('ip_hash', $ipHash)
                ->where('created_at >=', $since)
                ->countAllResults();
            if ($recent >= self::RATE_LIMIT) {
                throw new DomainException('Se alcanzó el límite temporal de intentos. Probá nuevamente más tarde.');
            }

            $equipment = $this->equipment((int) $access['empresa_id'], (int) $access['equipo_id']);
            if ($equipment === null) {
                throw new DomainException('El acceso QR no es válido o dejó de estar vigente.');
            }

            $kilometers = $this->nullableInt($this->request->getPost('kilometers'));
            $hours = $this->nullableHours($this->request->getPost('hours'));
            $notes = trim((string) $this->request->getPost('notes'));
            if (mb_strlen($notes) > 500) {
                throw new DomainException('La observación no puede superar 500 caracteres.');
            }

            if ((int) $equipment['controla_km'] === 1 && $kilometers === null) {
                throw new DomainException('Ingresá los kilómetros actuales.');
            }
            if ((int) $equipment['controla_horas'] === 1 && $hours === null) {
                throw new DomainException('Ingresá las horas actuales.');
            }
            if ($kilometers === null && $hours === null) {
                throw new DomainException('Ingresá una lectura.');
            }
            if ($kilometers !== null && $equipment['km_actual'] !== null && $kilometers < (int) $equipment['km_actual']) {
                throw new DomainException('La lectura no puede ser menor que el kilometraje actual.');
            }
            if ($hours !== null && $equipment['horas_actuales'] !== null && $hours < (float) $equipment['horas_actuales']) {
                throw new DomainException('La lectura no puede ser menor que el horómetro actual.');
            }

            $largeJump =
                ($kilometers !== null && $equipment['km_actual'] !== null && $kilometers - (int) $equipment['km_actual'] > self::MAX_KM_JUMP)
                || ($hours !== null && $equipment['horas_actuales'] !== null && $hours - (float) $equipment['horas_actuales'] > self::MAX_HOURS_JUMP);

            if ($largeJump && (string) $this->request->getPost('confirm_large_jump') !== '1') {
                throw new DomainException('La lectura tiene un salto inusualmente grande. Confirmá el valor antes de guardarlo.');
            }

            $now = date('Y-m-d H:i:s');
            $database->transBegin();
            try {
                $database->table('lecturas_equipo')->insert([
                    'empresa_id' => (int) $equipment['empresa_id'],
                    'sucursal_id' => (int) $equipment['sucursal_id'],
                    'equipo_id' => (int) $equipment['id'],
                    'fecha_lectura' => $now,
                    'kilometraje' => $kilometers,
                    'horometro' => $hours,
                    'origen' => 'QR_ANONIMO',
                    'referencia_origen' => 'PUBLIC_TOKEN#' . $tokenId,
                    'usuario_id' => null,
                    'observaciones' => $notes === '' ? null : $notes,
                    'anulada' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $readingId = (int) $database->insertID();

                $update = ['updated_at' => $now];
                if ($kilometers !== null) {
                    $update['km_actual'] = $kilometers;
                }
                if ($hours !== null) {
                    $update['horas_actuales'] = $hours;
                }
                $database->table('equipos')
                    ->where('id', (int) $equipment['id'])
                    ->where('empresa_id', (int) $equipment['empresa_id'])
                    ->update($update);

                $database->table('qr_lecturas_auditoria')->insert([
                    'token_id' => $tokenId,
                    'request_key' => $requestKey,
                    'ip_hash' => $ipHash,
                    'user_agent' => mb_substr((string) $this->request->getUserAgent(), 0, 255),
                    'resultado' => 'ACEPTADO',
                    'motivo' => null,
                    'lectura_id' => $readingId,
                    'created_at' => $now,
                ]);

                if (! $database->transStatus()) {
                    throw new DomainException('No se pudo guardar la lectura.');
                }
                $database->transCommit();
            } catch (Throwable $exception) {
                $database->transRollback();
                throw $exception;
            }

            return redirect()->to($target)->with('success', 'Lectura registrada correctamente. Gracias.');
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló lectura QR anónima: {message}', ['message' => $exception->getMessage()]);
            }

            if ($requestKey !== '') {
                $this->auditRejected($token, $requestKey, $exception->getMessage());
            }

            $redirect = redirect()->to($target)->withInput()->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo registrar la lectura.',
            );
            if (str_contains($exception->getMessage(), 'salto inusualmente grande')) {
                $redirect->with('large_jump', true);
            }
            return $redirect;
        }
    }

    private function auditRejected(string $token, string $requestKey, string $reason): void
    {
        try {
            $access = $this->resolve($token);
            $db = db_connect();
            if ($db->table('qr_lecturas_auditoria')->where('request_key', $requestKey)->countAllResults() > 0) {
                return;
            }
            $db->table('qr_lecturas_auditoria')->insert([
                'token_id' => (int) $access['id'],
                'request_key' => $requestKey,
                'ip_hash' => hash('sha256', (string) $this->request->getIPAddress()),
                'user_agent' => mb_substr((string) $this->request->getUserAgent(), 0, 255),
                'resultado' => 'RECHAZADO',
                'motivo' => mb_substr($reason, 0, 255),
                'lectura_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // La auditoría no debe convertir un error de usuario en un error 500.
        }
    }

    /** @return array<string,mixed> */
    private function resolve(string $token): array
    {
        return (new ResolvePublicEquipmentToken(
            new CodeIgniterPublicEquipmentTokenRepository(db_connect()),
        ))->execute($token);
    }

    /** @return array<string,mixed>|null */
    private function equipment(int $companyId, int $equipmentId): ?array
    {
        return db_connect()->table('equipos e')
            ->select('e.id, e.empresa_id, e.sucursal_id, e.codigo, e.patente, e.km_actual, e.horas_actuales, e.estado')
            ->select('te.nombre tipo_nombre, te.controla_km, te.controla_horas')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->get()
            ->getRowArray();
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException('El kilometraje no es válido.');
        }
        return (int) $value;
    }

    private function nullableHours(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            throw new DomainException('El horómetro no es válido.');
        }
        return round((float) $value, 1);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
