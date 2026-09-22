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
        $locale = 'ES';
        try {
            $access = $this->resolve($token);
            $equipment = $this->equipment((int) $access['empresa_id'], (int) $access['equipo_id']);
            if ($equipment === null) {
                throw new DomainException($this->tr($locale, 'invalid_access'));
            }
            $locale = $this->equipmentLocale($equipment);

            return view('public_equipment/reading', [
                'token' => $token,
                'equipment' => $equipment,
                'requestKey' => $this->uuid(),
                'success' => session()->getFlashdata('success'),
                'error' => session()->getFlashdata('error'),
                'largeJump' => (bool) session()->getFlashdata('large_jump'),
                'locale' => $locale,
                'labels' => $this->labels($locale),
            ]);
        } catch (Throwable $exception) {
            return view('public_equipment/invalid', [
                'locale' => $locale,
                'title' => $this->tr($locale, 'access_unavailable'),
                'message' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : $this->tr($locale, 'open_failed'),
            ]);
        }
    }

    public function store(string $token): RedirectResponse
    {
        $target = base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura');
        $requestKey = trim((string) $this->request->getPost('request_key'));
        $locale = 'ES';

        try {
            $access = $this->resolve($token);
            $tokenId = (int) $access['id'];
            $database = db_connect();
            $equipment = $this->equipment((int) $access['empresa_id'], (int) $access['equipo_id']);
            if ($equipment === null) {
                throw new DomainException($this->tr($locale, 'invalid_access'));
            }
            $locale = $this->equipmentLocale($equipment);
            $ipHash = hash('sha256', (string) $this->request->getIPAddress());

            if ($requestKey === '' || strlen($requestKey) > 36) {
                throw new DomainException($this->tr($locale, 'invalid_request'));
            }

            $existing = $database->table('qr_lecturas_auditoria')
                ->where('request_key', $requestKey)
                ->get()
                ->getRowArray();
            if ($existing !== null && ($existing['resultado'] ?? '') === 'ACEPTADO') {
                return redirect()->to($target)->with('success', $this->tr($locale, 'already_registered'));
            }

            $since = date('Y-m-d H:i:s', time() - (self::RATE_WINDOW_MINUTES * 60));
            $recent = $database->table('qr_lecturas_auditoria')
                ->where('token_id', $tokenId)
                ->where('ip_hash', $ipHash)
                ->where('created_at >=', $since)
                ->countAllResults();
            if ($recent >= self::RATE_LIMIT) {
                throw new DomainException($this->tr($locale, 'rate_limit'));
            }

            $kilometers = $this->nullableInt($this->request->getPost('kilometers'), $locale);
            $hours = $this->nullableHours($this->request->getPost('hours'), $locale);
            $notes = trim((string) $this->request->getPost('notes'));
            if (mb_strlen($notes) > 500) {
                throw new DomainException($this->tr($locale, 'notes_too_long'));
            }

            if ((int) $equipment['controla_km'] === 1 && $kilometers === null) {
                throw new DomainException($this->tr($locale, 'km_required'));
            }
            if ((int) $equipment['controla_horas'] === 1 && $hours === null) {
                throw new DomainException($this->tr($locale, 'hours_required'));
            }
            if ($kilometers === null && $hours === null) {
                throw new DomainException($this->tr($locale, 'reading_required'));
            }
            if ($kilometers !== null && $equipment['km_actual'] !== null && $kilometers < (int) $equipment['km_actual']) {
                throw new DomainException($this->tr($locale, 'km_lower'));
            }
            if ($hours !== null && $equipment['horas_actuales'] !== null && $hours < (float) $equipment['horas_actuales']) {
                throw new DomainException($this->tr($locale, 'hours_lower'));
            }

            $largeJump =
                ($kilometers !== null && $equipment['km_actual'] !== null && $kilometers - (int) $equipment['km_actual'] > self::MAX_KM_JUMP)
                || ($hours !== null && $equipment['horas_actuales'] !== null && $hours - (float) $equipment['horas_actuales'] > self::MAX_HOURS_JUMP);

            if ($largeJump && (string) $this->request->getPost('confirm_large_jump') !== '1') {
                throw new DomainException($this->tr($locale, 'large_jump'));
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
                    throw new DomainException($this->tr($locale, 'save_failed'));
                }
                $database->transCommit();
            } catch (Throwable $exception) {
                $database->transRollback();
                throw $exception;
            }

            return redirect()->to($target)->with('success', $this->tr($locale, 'success'));
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló lectura QR anónima: {message}', ['message' => $exception->getMessage()]);
            }

            if ($requestKey !== '') {
                $this->auditRejected($token, $requestKey, $exception->getMessage());
            }

            $redirect = redirect()->to($target)->withInput()->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : $this->tr($locale, 'register_failed'),
            );
            if ($exception instanceof DomainException && $exception->getMessage() === $this->tr($locale, 'large_jump')) {
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
            ->select('co.idioma_notificaciones empresa_idioma_notificaciones')
            ->select('s.idioma_notificaciones sucursal_idioma_notificaciones')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('empresas co', 'co.id = e.empresa_id', 'inner')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->get()
            ->getRowArray();
    }

    private function nullableInt(mixed $value, string $locale): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException($this->tr($locale, 'invalid_km'));
        }
        return (int) $value;
    }

    private function nullableHours(mixed $value, string $locale): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            throw new DomainException($this->tr($locale, 'invalid_hours'));
        }
        return round((float) $value, 1);
    }

    /** @param array<string,mixed> $equipment */
    private function equipmentLocale(array $equipment): string
    {
        $branchLocale = trim((string) ($equipment['sucursal_idioma_notificaciones'] ?? ''));
        if ($branchLocale !== '') {
            return $this->normalizeLocale($branchLocale);
        }

        return $this->normalizeLocale((string) ($equipment['empresa_idioma_notificaciones'] ?? 'ES'));
    }

    private function normalizeLocale(string $locale): string
    {
        return strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
    }

    /** @return array<string,string> */
    private function labels(string $locale): array
    {
        $locale = $this->normalizeLocale($locale);

        if ($locale === 'PT') {
            return [
                'title' => 'Registrar leitura',
                'last_km' => 'Última quilometragem',
                'last_hours' => 'Último horímetro',
                'current_km' => 'Quilometragem atual',
                'current_hours' => 'Horas atuais',
                'notes' => 'Observação (opcional)',
                'confirm_jump' => 'Confirmo que revisei o valor e ele está correto.',
                'submit' => 'Registrar leitura',
            ];
        }

        return [
            'title' => 'Registrar lectura',
            'last_km' => 'Último kilometraje',
            'last_hours' => 'Último horómetro',
            'current_km' => 'Kilómetros actuales',
            'current_hours' => 'Horas actuales',
            'notes' => 'Observación (opcional)',
            'confirm_jump' => 'Confirmo que revisé el valor y es correcto.',
            'submit' => 'Registrar lectura',
        ];
    }

    private function tr(string $locale, string $key): string
    {
        $pt = [
            'invalid_access' => 'O acesso público não é válido ou não está mais disponível.',
            'access_unavailable' => 'Acesso indisponível',
            'open_failed' => 'Não foi possível abrir o acesso público.',
            'invalid_request' => 'A solicitação não é válida. Abra novamente o link do equipamento.',
            'already_registered' => 'A leitura já havia sido registrada.',
            'rate_limit' => 'O limite temporário de tentativas foi atingido. Tente novamente mais tarde.',
            'notes_too_long' => 'A observação não pode exceder 500 caracteres.',
            'km_required' => 'Informe a quilometragem atual.',
            'hours_required' => 'Informe as horas atuais.',
            'reading_required' => 'Informe uma leitura.',
            'km_lower' => 'A leitura não pode ser menor que a quilometragem atual.',
            'hours_lower' => 'A leitura não pode ser menor que o horímetro atual.',
            'large_jump' => 'A leitura apresenta uma variação incomumente grande. Confirme o valor antes de salvar.',
            'save_failed' => 'Não foi possível salvar a leitura.',
            'success' => 'Leitura registrada com sucesso. Obrigado.',
            'register_failed' => 'Não foi possível registrar a leitura.',
            'invalid_km' => 'A quilometragem não é válida.',
            'invalid_hours' => 'O horímetro não é válido.',
        ];
        $es = [
            'invalid_access' => 'El acceso público no es válido o dejó de estar vigente.',
            'access_unavailable' => 'Acceso no disponible',
            'open_failed' => 'No se pudo abrir el acceso público.',
            'invalid_request' => 'La solicitud no es válida. Volvé a abrir el enlace del equipo.',
            'already_registered' => 'La lectura ya había sido registrada.',
            'rate_limit' => 'Se alcanzó el límite temporal de intentos. Probá nuevamente más tarde.',
            'notes_too_long' => 'La observación no puede superar 500 caracteres.',
            'km_required' => 'Ingresá los kilómetros actuales.',
            'hours_required' => 'Ingresá las horas actuales.',
            'reading_required' => 'Ingresá una lectura.',
            'km_lower' => 'La lectura no puede ser menor que el kilometraje actual.',
            'hours_lower' => 'La lectura no puede ser menor que el horómetro actual.',
            'large_jump' => 'La lectura tiene un salto inusualmente grande. Confirmá el valor antes de guardarlo.',
            'save_failed' => 'No se pudo guardar la lectura.',
            'success' => 'Lectura registrada correctamente. Gracias.',
            'register_failed' => 'No se pudo registrar la lectura.',
            'invalid_km' => 'El kilometraje no es válido.',
            'invalid_hours' => 'El horómetro no es válido.',
        ];

        $catalog = $this->normalizeLocale($locale) === 'PT' ? $pt : $es;
        return $catalog[$key] ?? $key;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
