<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\PublicEquipmentAccess\ResolvePublicEquipmentToken;
use App\Infrastructure\PublicEquipmentAccess\CodeIgniterPublicEquipmentTokenRepository;
use App\Infrastructure\Measurement\MiniMaxOdometerImageAnalyzer;
use App\Infrastructure\Measurement\ReadingEvidenceStorage;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class PublicEquipmentReadings extends BaseController
{
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW_MINUTES = 10;
    private const MAX_KM_JUMP = 5000;
    private const MAX_HOURS_JUMP = 500.0;
    private const MAX_EVIDENCE_BYTES = 8 * 1024 * 1024;

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

            $registered = (string) $this->request->getGet('registrada') === '1';

            return view('public_equipment/reading', [
                'token' => $token,
                'equipment' => $equipment,
                'requestKey' => $registered ? '' : $this->uuid(),
                'registered' => $registered,
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

    public function analyze(string $token): ResponseInterface
    {
        $stored = null;
        $equipment = null;

        try {
            $access = $this->resolve($token);
            $equipment = $this->equipment((int) $access['empresa_id'], (int) $access['equipo_id']);
            if ($equipment === null) {
                throw new DomainException('El acceso público no es válido.');
            }

            [$tempPath, $mime] = $this->validatedEvidenceUpload();

            // La IA no depende del almacenamiento persistente: primero analizamos
            // el archivo temporal recibido. Si luego falla el staging, el chofer
            // igual puede ver la lectura propuesta y enviar la misma foto en el POST final.
            $analysis = null;
            $analysisError = null;
            try {
                $analysis = MiniMaxOdometerImageAnalyzer::fromEnv()->analyze($tempPath, $mime);
            } catch (Throwable $exception) {
                $analysisError = $exception->getMessage();
                log_message('notice', 'No se pudo analizar evidencia de lectura: {message}', [
                    'message' => $analysisError,
                ]);
            }

            $evidenceRef = null;
            $storageError = null;
            try {
                $stored = $this->evidenceStorage()->store(
                    $tempPath,
                    (int) $equipment['empresa_id'],
                    $mime,
                );

                $evidenceRef = bin2hex(random_bytes(16));
                session()->set($this->stagedEvidenceSessionKey($evidenceRef), [
                    'path' => $stored['path'],
                    'mime' => $stored['mime'],
                    'bytes' => $stored['bytes'],
                    'company_id' => (int) $equipment['empresa_id'],
                    'equipment_id' => (int) $equipment['id'],
                    'expires_at' => time() + 1800,
                    'km_detectado_ia' => $analysis?->kilometers,
                    'confianza_ia' => $analysis?->confidence,
                    'legible_ia' => $analysis?->legible ?? false,
                    'observacion_ia' => $analysis?->observation,
                ]);
            } catch (Throwable $exception) {
                $storageError = $exception->getMessage();
                log_message('error', 'No se pudo dejar staged la evidencia de lectura: {message}', [
                    'message' => $storageError,
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'kilometers' => $analysis?->kilometers,
                'confidence' => $analysis?->confidence,
                'legible' => $analysis?->legible ?? false,
                'observation' => $analysis?->observation,
                'analysisError' => $analysis === null
                    ? 'No pudimos leer automáticamente el odómetro. Podés ingresar el kilometraje manualmente.'
                    : null,
                'evidenceRef' => $evidenceRef,
                'storageReady' => $evidenceRef !== null,
                'storageError' => $storageError,
                'csrfToken' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        } catch (Throwable $exception) {
            if ($stored !== null && $equipment !== null) {
                try {
                    $this->evidenceStorage()->delete((string) $stored['path'], (int) $equipment['empresa_id']);
                } catch (Throwable) {
                }
            }

            log_message('notice', 'No se pudo preparar evidencia de lectura: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(422)->setJSON([
                'ok' => false,
                'error' => $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No pudimos procesar la foto. Volvé a tomarla.',
                'csrfToken' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }
    }

    public function evidence(int $readingId): ResponseInterface
    {
        $actor = (new \App\Infrastructure\Identity\SessionActorContext())->current();
        if ($actor === null) {
            return $this->response->setStatusCode(401);
        }

        $row = db_connect()->table('lecturas_equipo_evidencias ev')
            ->select('ev.archivo_path, ev.mime_type, ev.empresa_id')
            ->join('lecturas_equipo le', 'le.id = ev.lectura_id AND le.empresa_id = ev.empresa_id', 'inner')
            ->where('ev.lectura_id', $readingId)
            ->where('ev.empresa_id', $actor->companyId())
            ->get()
            ->getRowArray();

        if ($row === null) {
            return $this->response->setStatusCode(404);
        }

        try {
            $content = $this->evidenceStorage()->read((string) $row['archivo_path'], (int) $row['empresa_id']);
            return $this->response
                ->setHeader('Content-Type', (string) $row['mime_type'])
                ->setHeader('Cache-Control', 'private, max-age=300')
                ->setBody($content);
        } catch (Throwable $exception) {
            log_message('warning', 'No se pudo leer evidencia de lectura: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(404);
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
                return redirect()->to($target . '?registrada=1')->with('success', $this->tr($locale, 'already_registered'));
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

            $evidenceRef = trim((string) $this->request->getPost('evidence_ref'));
            $stagedEvidence = $this->stagedEvidence(
                $evidenceRef,
                (int) $equipment['empresa_id'],
                (int) $equipment['id'],
            );

            $evidenceTempPath = null;
            $evidenceMime = null;
            $aiAnalysis = null;

            if ($stagedEvidence !== null) {
                $aiAnalysis = new \App\Application\Measurement\OdometerImageAnalysis(
                    isset($stagedEvidence['km_detectado_ia']) && $stagedEvidence['km_detectado_ia'] !== null
                        ? (int) $stagedEvidence['km_detectado_ia']
                        : null,
                    isset($stagedEvidence['confianza_ia']) && $stagedEvidence['confianza_ia'] !== null
                        ? (float) $stagedEvidence['confianza_ia']
                        : null,
                    (bool) ($stagedEvidence['legible_ia'] ?? false),
                    isset($stagedEvidence['observacion_ia']) && $stagedEvidence['observacion_ia'] !== null
                        ? (string) $stagedEvidence['observacion_ia']
                        : null,
                );
            } else {
                [$evidenceTempPath, $evidenceMime] = $this->validatedEvidenceUpload();
                try {
                    $aiAnalysis = MiniMaxOdometerImageAnalyzer::fromEnv()->analyze($evidenceTempPath, $evidenceMime);
                } catch (Throwable $exception) {
                    log_message('notice', 'IA de odómetro no disponible; se continúa con carga manual: {message}', [
                        'message' => $exception->getMessage(),
                    ]);
                }
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

            $duplicateSince = date('Y-m-d H:i:s', time() - 120);
            $duplicate = $database->table('lecturas_equipo')
                ->where('empresa_id', (int) $equipment['empresa_id'])
                ->where('equipo_id', (int) $equipment['id'])
                ->where('origen', 'QR_ANONIMO')
                ->where('referencia_origen', 'PUBLIC_TOKEN#' . $tokenId)
                ->where('anulada', 0)
                ->where('created_at >=', $duplicateSince);

            $kilometers === null
                ? $duplicate->where('kilometraje', null)
                : $duplicate->where('kilometraje', $kilometers);
            $hours === null
                ? $duplicate->where('horometro', null)
                : $duplicate->where('horometro', $hours);

            if ($duplicate->countAllResults() > 0) {
                return redirect()->to($target . '?registrada=1')->with('success', $this->tr($locale, 'already_registered'));
            }

            if (! $database->tableExists('lecturas_equipo_evidencias')) {
                throw new DomainException(
                    $this->normalizeLocale($locale) === 'PT'
                        ? 'A atualização de evidências ainda não foi aplicada neste ambiente. Execute as migrações antes de registrar.'
                        : 'La actualización de evidencias todavía no fue aplicada en este entorno. Ejecutá las migraciones antes de registrar.'
                );
            }

            $now = date('Y-m-d H:i:s');
            $storedEvidence = $stagedEvidence !== null
                ? [
                    'path' => (string) $stagedEvidence['path'],
                    'mime' => (string) $stagedEvidence['mime'],
                    'bytes' => (int) $stagedEvidence['bytes'],
                ]
                : $this->evidenceStorage()->store(
                    (string) $evidenceTempPath,
                    (int) $equipment['empresa_id'],
                    (string) $evidenceMime,
                );
            $database->transBegin();
            try {
                $readingInserted = $database->table('lecturas_equipo')->insert([
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
                if ($readingInserted !== true) {
                    $dbError = $database->error();
                    log_message('error', 'Falló insert de lectura QR: {code} {message}', [
                        'code' => (string) ($dbError['code'] ?? ''),
                        'message' => (string) ($dbError['message'] ?? ''),
                    ]);
                    throw new DomainException($this->tr($locale, 'save_failed'));
                }
                $readingId = (int) $database->insertID();

                $detectedKm = $aiAnalysis?->kilometers;
                $confidence = $aiAnalysis?->confidence;
                $aiStatus = $aiAnalysis === null
                    ? 'NO_DISPONIBLE'
                    : ($aiAnalysis->legible ? 'DETECTADO' : 'NO_LEGIBLE');
                $method = $detectedKm === null
                    ? 'FOTO_MANUAL'
                    : ($kilometers === $detectedKm ? 'FOTO_IA_CONFIRMADA' : 'FOTO_IA_CORREGIDA');

                $aiObservation = $aiAnalysis?->observation;
                if ($aiObservation !== null) {
                    $aiObservation = mb_substr($aiObservation, 0, 255);
                }

                $evidenceInserted = $database->table('lecturas_equipo_evidencias')->insert([
                    'empresa_id' => (int) $equipment['empresa_id'],
                    'lectura_id' => $readingId,
                    'archivo_path' => $storedEvidence['path'],
                    'mime_type' => $storedEvidence['mime'],
                    'archivo_bytes' => $storedEvidence['bytes'],
                    'km_detectado_ia' => $detectedKm,
                    'confianza_ia' => $confidence,
                    'estado_ia' => $aiStatus,
                    'km_confirmado' => $kilometers,
                    'metodo_carga' => $method,
                    'observacion_ia' => $aiObservation,
                    'created_at' => $now,
                ]);
                if ($evidenceInserted !== true) {
                    $dbError = $database->error();
                    log_message('error', 'Falló insert de evidencia de lectura: {code} {message}', [
                        'code' => (string) ($dbError['code'] ?? ''),
                        'message' => (string) ($dbError['message'] ?? ''),
                    ]);
                    throw new DomainException(
                        $this->normalizeLocale($locale) === 'PT'
                            ? 'Não foi possível salvar a evidência da leitura.'
                            : 'No se pudo guardar la evidencia de la lectura.'
                    );
                }

                $update = ['updated_at' => $now];
                if ($kilometers !== null) {
                    $update['km_actual'] = $kilometers;
                }
                if ($hours !== null) {
                    $update['horas_actuales'] = $hours;
                }
                $equipmentUpdated = $database->table('equipos')
                    ->where('id', (int) $equipment['id'])
                    ->where('empresa_id', (int) $equipment['empresa_id'])
                    ->update($update);
                if ($equipmentUpdated !== true) {
                    $dbError = $database->error();
                    log_message('error', 'Falló actualización del equipo tras lectura QR: {code} {message}', [
                        'code' => (string) ($dbError['code'] ?? ''),
                        'message' => (string) ($dbError['message'] ?? ''),
                    ]);
                    throw new DomainException($this->tr($locale, 'save_failed'));
                }

                $auditInserted = $database->table('qr_lecturas_auditoria')->insert([
                    'token_id' => $tokenId,
                    'request_key' => $requestKey,
                    'ip_hash' => $ipHash,
                    'user_agent' => mb_substr((string) $this->request->getUserAgent(), 0, 255),
                    'resultado' => 'ACEPTADO',
                    'motivo' => null,
                    'lectura_id' => $readingId,
                    'created_at' => $now,
                ]);
                if ($auditInserted !== true) {
                    $dbError = $database->error();
                    log_message('error', 'Falló auditoría aceptada de lectura QR: {code} {message}', [
                        'code' => (string) ($dbError['code'] ?? ''),
                        'message' => (string) ($dbError['message'] ?? ''),
                    ]);
                    throw new DomainException($this->tr($locale, 'save_failed'));
                }

                if (! $database->transStatus()) {
                    throw new DomainException($this->tr($locale, 'save_failed'));
                }
                $database->transCommit();
                if ($evidenceRef !== '') {
                    session()->remove($this->stagedEvidenceSessionKey($evidenceRef));
                }
            } catch (Throwable $exception) {
                $database->transRollback();
                if ($stagedEvidence === null && isset($storedEvidence['path'])) {
                    $this->evidenceStorage()->delete((string) $storedEvidence['path'], (int) $equipment['empresa_id']);
                }
                throw $exception;
            }

            return redirect()->to($target . '?registrada=1')->with('success', $this->tr($locale, 'success'));
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

    /** @return array<string,mixed>|null */
    private function stagedEvidence(string $reference, int $companyId, int $equipmentId): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $reference)) {
            return null;
        }

        $key = $this->stagedEvidenceSessionKey($reference);
        $data = session()->get($key);
        if (! is_array($data)) {
            return null;
        }

        if ((int) ($data['company_id'] ?? 0) !== $companyId
            || (int) ($data['equipment_id'] ?? 0) !== $equipmentId
            || (int) ($data['expires_at'] ?? 0) < time()) {
            if (isset($data['path'])) {
                try {
                    $this->evidenceStorage()->delete((string) $data['path'], $companyId);
                } catch (Throwable) {
                }
            }
            session()->remove($key);
            return null;
        }

        return $data;
    }

    private function stagedEvidenceSessionKey(string $reference): string
    {
        return 'public_reading_evidence_' . $reference;
    }

    /** @return array{0:string,1:string} */
    private function validatedEvidenceUpload(): array
    {
        $file = $this->request->getFile('evidence_photo');
        if ($file === null) {
            throw new DomainException('La foto del tablero es obligatoria.');
        }
        if (! $file->isValid()) {
            $error = $file->getError();
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La foto es demasiado grande para subirla. Volvé a tomarla o elegí una imagen más liviana.',
                UPLOAD_ERR_PARTIAL => 'La foto se subió de forma incompleta. Volvé a intentarlo.',
                UPLOAD_ERR_NO_FILE => 'La foto del tablero es obligatoria.',
                default => 'No se pudo recibir la foto del tablero. Volvé a intentarlo.',
            };
            throw new DomainException($message);
        }
        if ($file->hasMoved()) {
            throw new DomainException('La foto del tablero ya no está disponible. Volvé a seleccionarla.');
        }
        if ($file->getSize() <= 0 || $file->getSize() > self::MAX_EVIDENCE_BYTES) {
            throw new DomainException('La foto del tablero supera el tamaño permitido de 8 MB.');
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new DomainException('La evidencia debe ser una foto JPG o PNG.');
        }

        $tempPath = $file->getTempName();
        if ($tempPath === '' || ! is_file($tempPath)) {
            throw new DomainException('No se pudo procesar la foto del tablero.');
        }

        return [$tempPath, $mime];
    }

    private function evidenceStorage(): ReadingEvidenceStorage
    {
        // Preferir una raíz específica para evidencias. Si no existe, derivarla
        // como hermana del storage privado ya probado de adjuntos, sin sondear
        // rutas externas que puedan disparar open_basedir en Ferozo.
        $configured = trim((string) env('uploads.readingEvidencePath', ''));
        if ($configured !== '') {
            return new ReadingEvidenceStorage($configured);
        }

        $privateRoot = rtrim(trim((string) env('uploads.privatePath', '')), '\\/');
        if ($privateRoot !== '') {
            return new ReadingEvidenceStorage(
                dirname($privateRoot) . DIRECTORY_SEPARATOR . 'lecturas',
            );
        }

        return new ReadingEvidenceStorage();
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
                'current_km' => 'Quantos quilômetros o painel mostra agora?',
                'current_km_help' => 'Exemplo: se o painel mostra 494497, digite 494497.',
                'photo' => 'Foto obrigatória do painel',
                'photo_help' => 'Tire uma foto nítida mostrando o hodômetro. A foto ficará salva como evidência.',
                'analyze' => 'Ler quilometragem com IA',
                'analyzing' => 'Analisando foto...',
                'current_hours' => 'Horas atuais',
                'notes' => 'Observação (opcional)',
                'confirm_jump' => 'Confirmo que revisei o valor e ele está correto.',
                'submit' => 'Registrar leitura',
            'saving' => 'Salvando...',
            'registered_title' => 'Leitura registrada',
            'registered_help' => 'A leitura foi salva corretamente. Você já pode fechar esta janela.',
            ];
        }

        return [
            'title' => 'Registrar lectura',
            'last_km' => 'Último kilometraje',
            'last_hours' => 'Último horómetro',
            'current_km' => '¿Cuántos kilómetros marca ahora el tablero?',
            'current_km_help' => 'Ejemplo: si el tablero muestra 494497, escribí 494497.',
            'photo' => 'Foto obligatoria del tablero',
            'photo_help' => 'Sacá una foto nítida donde se vea el odómetro. La foto quedará guardada como evidencia.',
            'analyze' => 'Leer kilometraje con IA',
            'analyzing' => 'Analizando foto...',
            'current_hours' => 'Horas actuales',
            'notes' => 'Observación (opcional)',
            'confirm_jump' => 'Confirmo que revisé el valor y es correcto.',
            'submit' => 'Registrar lectura',
            'saving' => 'Guardando...',
            'registered_title' => 'Lectura registrada',
            'registered_help' => 'La lectura quedó guardada correctamente. Ya podés cerrar esta ventana.',
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
