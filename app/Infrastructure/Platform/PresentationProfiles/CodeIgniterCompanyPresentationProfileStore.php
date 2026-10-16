<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\PresentationProfiles;

use App\Application\Platform\PresentationProfiles\Port\CompanyPresentationProfileStore;
use App\Application\Platform\PresentationProfiles\PresentationProfileAlreadyAssigned;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final class CodeIgniterCompanyPresentationProfileStore implements CompanyPresentationProfileStore
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function assign(int $companyId, string $profileKey, int $actorUserId, bool $exclusive): void
    {
        if ($companyId <= 0 || $actorUserId <= 0 || trim($profileKey) === '') {
            throw new DomainException('La empresa, el actor y el perfil son obligatorios.');
        }

        if (! $this->database->transBegin()) {
            throw new RuntimeException('No se pudo iniciar la asignación del perfil de presentación.');
        }

        try {
            $company = $this->database->table('empresas')
                ->select('id')
                ->where('id', $companyId)
                ->where('estado', 1)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($company === null) {
                throw new DomainException('La empresa no existe o no está activa.');
            }

            if ($exclusive && $this->database->table('empresa_configuracion_presentacion')
                ->where('perfil_exclusivo', $profileKey)
                ->where('empresa_id !=', $companyId)
                ->countAllResults() > 0) {
                throw new PresentationProfileAlreadyAssigned();
            }

            $configuration = $this->database->table('empresa_configuracion_presentacion')
                ->where('empresa_id', $companyId)
                ->get()
                ->getRowArray();

            if ($configuration !== null && $configuration['frontend_profile'] === $profileKey) {
                if (! $this->database->transCommit()) {
                    throw new RuntimeException('No se pudo completar la asignación del perfil de presentación.');
                }

                return;
            }

            $now = date('Y-m-d H:i:s');
            $exclusiveKey = $exclusive ? $profileKey : null;

            if ($configuration === null) {
                $saved = $this->database->table('empresa_configuracion_presentacion')->insert([
                    'empresa_id' => $companyId,
                    'frontend_profile' => $profileKey,
                    'perfil_exclusivo' => $exclusiveKey,
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $saved = $this->database->table('empresa_configuracion_presentacion')
                    ->where('empresa_id', $companyId)
                    ->update([
                        'frontend_profile' => $profileKey,
                        'perfil_exclusivo' => $exclusiveKey,
                        'updated_by' => $actorUserId,
                        'updated_at' => $now,
                    ]);
            }

            if (! $saved) {
                throw new RuntimeException((string) ($this->database->error()['message'] ?? 'No se pudo guardar la configuración.'));
            }

            $historySaved = $this->database->table('empresa_presentacion_perfil_historial')->insert([
                'empresa_id' => $companyId,
                'perfil_anterior' => $configuration['frontend_profile'] ?? null,
                'perfil_nuevo' => $profileKey,
                'actor_user_id' => $actorUserId,
                'created_at' => $now,
            ]);

            if (! $historySaved) {
                throw new RuntimeException((string) ($this->database->error()['message'] ?? 'No se pudo registrar la auditoría.'));
            }

            if (! $this->database->transStatus() || ! $this->database->transCommit()) {
                throw new RuntimeException('No se pudo confirmar la asignación del perfil de presentación.');
            }
        } catch (Throwable $error) {
            $exclusiveConflict = $this->isExclusiveProfileConflict($error);
            $this->database->transRollback();

            if ($exclusiveConflict) {
                throw new PresentationProfileAlreadyAssigned();
            }

            throw $error;
        }
    }

    private function isExclusiveProfileConflict(Throwable $error): bool
    {
        $databaseError = $this->database->error();
        $message = strtolower($error->getMessage() . ' ' . (string) ($databaseError['message'] ?? ''));

        return str_contains($message, 'perfil_exclusivo')
            || str_contains($message, 'uq_empresa_presentacion_perfil_exclusivo');
    }
}
