<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Expirations\ExpirationRegularization;
use App\Infrastructure\Expirations\CodeIgniterExpirationRegularizationRepository;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class ExpirationRegularizationDiagnostics extends BaseController
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
        if (! $db->tableExists('vencimiento_regularizaciones') || ! $db->tableExists('vencimiento_regularizacion_adjuntos')) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'error' => 'migration_pending',
                'message' => 'Aplicá primero las migraciones pendientes desde Superadmin.',
            ]);
        }

        $candidate = $db->table('vencimientos v')
            ->select('v.id, v.empresa_id')
            ->join(
                'vencimiento_regularizaciones r',
                "r.vencimiento_id = v.id AND r.empresa_id = v.empresa_id AND r.estado = 'PENDIENTE'",
                'left'
            )
            ->where('v.activo', 1)
            ->where('v.deleted_at', null)
            ->where('r.id', null)
            ->orderBy('v.id', 'ASC')
            ->get()->getRowArray();

        if ($candidate === null) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'error' => 'no_candidate',
                'message' => 'No existe un vencimiento activo libre para ejecutar la prueba.',
            ]);
        }

        $companyId = (int) $candidate['empresa_id'];
        $expirationId = (int) $candidate['id'];
        $repository = new CodeIgniterExpirationRegularizationRepository($db);
        $db->transBegin();

        try {
            $original = $db->table('vencimientos')->where('id', $expirationId)->get()->getRowArray();
            if ($original === null) {
                throw new DomainException('No se pudo releer el vencimiento de prueba.');
            }

            $firstId = $repository->createPending(new ExpirationRegularization(
                $companyId,
                $expirationId,
                null,
                new DateTimeImmutable('today'),
                new DateTimeImmutable('+1 year'),
                notes: 'DIAGNOSTICO HITO 413',
            ));

            $pendingCreated = $repository->hasPending($companyId, $expirationId);
            $duplicateBlocked = false;
            try {
                $repository->createPending(new ExpirationRegularization(
                    $companyId,
                    $expirationId,
                    null,
                    new DateTimeImmutable('today'),
                    new DateTimeImmutable('+2 years'),
                ));
            } catch (DomainException) {
                $duplicateBlocked = true;
            }

            $attachmentId = $repository->addAttachment(
                $companyId,
                $firstId,
                'diagnostics/hito-413.pdf',
                'hito-413.pdf',
                'application/pdf',
                1234,
            );

            $repository->reject($companyId, $firstId, $actor->userId(), 'Rechazo de diagnóstico', date('Y-m-d H:i:s'));
            $pendingReleased = ! $repository->hasPending($companyId, $expirationId);

            $secondId = $repository->createPending(new ExpirationRegularization(
                $companyId,
                $expirationId,
                null,
                new DateTimeImmutable('today'),
                new DateTimeImmutable('+2 years'),
                notes: 'SEGUNDO INTENTO DIAGNOSTICO HITO 413',
            ));

            $after = $db->table('vencimientos')->where('id', $expirationId)->get()->getRowArray();
            $expirationUnchanged = $after === $original;

            $ok = $pendingCreated && $duplicateBlocked && $attachmentId > 0 && $pendingReleased && $secondId > 0 && $expirationUnchanged;
            $db->transRollback();

            return $this->response->setStatusCode($ok ? 200 : 500)->setJSON([
                'status' => $ok ? 'ok' : 'error',
                'hito' => 413,
                'company_id' => $companyId,
                'expiration_id' => $expirationId,
                'checks' => [
                    'pending_created' => $pendingCreated,
                    'duplicate_pending_blocked' => $duplicateBlocked,
                    'attachment_linked' => $attachmentId > 0,
                    'rejection_releases_pending_slot' => $pendingReleased,
                    'new_attempt_after_rejection' => $secondId > 0,
                    'original_expiration_unchanged' => $expirationUnchanged,
                    'rollback_cleanup' => true,
                ],
                'message' => $ok
                    ? 'HITO_413=OK. La prueba fue transaccional y se revirtió; no dejó datos de diagnóstico.'
                    : 'HITO_413=FAIL. Revisar checks.',
            ]);
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Diagnóstico Hito 413 falló: {message}', ['message' => $exception->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'hito' => 413,
                'message' => 'HITO_413=FAIL',
                'detail' => ENVIRONMENT === 'development' ? $exception->getMessage() : 'Consultar logs de staging.',
            ]);
        }
    }
}
