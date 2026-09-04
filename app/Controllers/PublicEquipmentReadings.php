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
                'success' => session()->getFlashdata('success'),
                'error' => session()->getFlashdata('error'),
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

        try {
            $access = $this->resolve($token);
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

            $database = db_connect();
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
                    'referencia_origen' => 'PUBLIC_TOKEN#' . (int) $access['id'],
                    'usuario_id' => null,
                    'observaciones' => $notes === '' ? null : $notes,
                    'anulada' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

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

                if (! $database->transStatus()) {
                    throw new DomainException('No se pudo guardar la lectura.');
                }
                $database->transCommit();
            } catch (Throwable $exception) {
                $database->transRollback();
                throw $exception;
            }

            return redirect()->to($target)->with(
                'success',
                'Lectura registrada correctamente. Gracias.',
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló lectura QR anónima: {message}', ['message' => $exception->getMessage()]);
            }

            return redirect()->to($target)->withInput()->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo registrar la lectura.',
            );
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
}
