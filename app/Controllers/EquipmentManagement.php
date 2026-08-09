<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\DecommissionEquipmentCommand;
use App\Application\Assets\DecommissionEquipmentHandler;
use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\CreateEquipmentRelationCommand;
use App\Application\Assets\CreateEquipmentRelationHandler;
use App\Application\Assets\EquipmentListQuery;
use App\Application\Assets\FinishEquipmentRelationCommand;
use App\Application\Assets\FinishEquipmentRelationHandler;
use App\Application\Assets\Attachment\DownloadEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\DownloadEquipmentAttachmentQuery;
use App\Application\Assets\Attachment\ListEquipmentAttachmentsHandler;
use App\Application\Assets\Attachment\ListEquipmentAttachmentsQuery;
use App\Application\Assets\Attachment\RetireEquipmentAttachmentCommand;
use App\Application\Assets\Attachment\RetireEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentCommand;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentHandler;
use App\Application\Assets\GetEquipmentDetails;
use App\Application\Assets\ListEquipment;
use App\Application\Assets\TransferEquipmentCommand;
use App\Application\Assets\TransferEquipmentHandler;
use App\Application\Assets\UpdateEquipmentCommand;
use App\Application\Assets\UpdateEquipmentHandler;
use App\Application\Identity\ActorContext;
use App\Application\Measurement\CorrectReadingCommand;
use App\Application\Measurement\CorrectReadingHandler;
use App\Application\Measurement\ListReadingHistoryHandler;
use App\Application\Measurement\ListReadingHistoryQuery;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class EquipmentManagement extends BaseController
{
    public function show(int $equipmentId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $page = max(1, (int) $this->request->getGet('page'));
            $transferPage = max(1, (int) $this->request->getGet('transfer_page'));
            $attachmentPage = max(1, (int) $this->request->getGet('attachment_page'));
            $relationPage = max(1, (int) $this->request->getGet('relation_page'));
            $details = $this->details()->execute($actor, $equipmentId, $transferPage, 20, $relationPage, 20);
            $readings = $actor->hasPermission('lecturas.ver')
                ? $this->history()->execute($actor, new ListReadingHistoryQuery($equipmentId, $page, 20))
                : null;
            $attachments = $this->listAttachments()->execute(
                $actor,
                new ListEquipmentAttachmentsQuery($equipmentId, $attachmentPage, 20),
            );
            $catalogs = $this->assetCatalog()->list($actor);
            $relatedCandidates = $actor->hasPermission('equipos.editar')
                ? $this->equipmentList()->execute($actor, new EquipmentListQuery(status: 'ACTIVO', perPage: 100))['items']
                : [];

            return $this->renderApp(
                $actor,
                'equipment',
                'equipment-detail',
                'Ficha del equipo',
                service('operationsPayload')->equipmentDetails(
                    $details,
                    $readings,
                    $attachments,
                    $catalogs,
                    $relatedCandidates,
                    [
                    'edit' => $actor->hasPermission('equipos.editar'),
                    'correctReadings' => $actor->hasPermission('lecturas.corregir'),
                    ],
                ),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento');
        }
    }

    public function createRelation(int $equipmentId): RedirectResponse
    {
        try {
            $result = $this->createRelationHandler()->execute($this->actor(), new CreateEquipmentRelationCommand(
                $equipmentId,
                (int) $this->request->getPost('equipo_relacionado_id'),
                (string) $this->request->getPost('tipo_relacion'),
                $this->dateTimeValue((string) $this->request->getPost('desde'), 'La fecha de inicio de relación no es válida.'),
                $this->nullableString($this->request->getPost('observaciones')),
            ));

            return $this->success($equipmentId, "Relación #{$result->relationId} registrada correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function finishRelation(int $equipmentId, int $relationId): RedirectResponse
    {
        try {
            $this->finishRelationHandler()->execute($this->actor(), new FinishEquipmentRelationCommand(
                $relationId,
                $this->dateTimeValue((string) $this->request->getPost('hasta'), 'La fecha de fin de relación no es válida.'),
                $this->nullableString($this->request->getPost('observaciones_fin')),
            ));

            return $this->success($equipmentId, "Relación #{$relationId} finalizada; el historial fue conservado.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function uploadAttachment(int $equipmentId): RedirectResponse
    {
        try {
            $file = $this->request->getFile('archivo');
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('Seleccioná un archivo válido para adjuntar.');
            }

            $attachmentId = $this->uploadAttachmentHandler()->execute(
                $this->actor(),
                new UploadEquipmentAttachmentCommand(
                    $equipmentId,
                    $file->getTempName(),
                    $file->getClientName(),
                    (string) $this->request->getPost('tipo'),
                    $this->nullableString($this->request->getPost('descripcion')),
                ),
            );

            return $this->success($equipmentId, "Adjunto #{$attachmentId} guardado en almacenamiento privado.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function downloadAttachment(int $equipmentId, int $attachmentId): ResponseInterface|RedirectResponse
    {
        try {
            $download = $this->downloadAttachmentHandler()->execute(
                $this->actor(),
                new DownloadEquipmentAttachmentQuery($equipmentId, $attachmentId),
            );

            return $this->response
                ->download($download->originalName, $download->content, false)
                ->setContentType($download->mimeType)
                ->setHeader('X-Content-Type-Options', 'nosniff');
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function retireAttachment(int $equipmentId, int $attachmentId): RedirectResponse
    {
        try {
            $this->retireAttachmentHandler()->execute(
                $this->actor(),
                new RetireEquipmentAttachmentCommand(
                    $equipmentId,
                    $attachmentId,
                    (string) $this->request->getPost('motivo'),
                ),
            );

            return $this->success($equipmentId, "Adjunto #{$attachmentId} retirado; el historial fue conservado.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function update(int $equipmentId): RedirectResponse
    {
        try {
            $result = $this->updateHandler()->execute($this->actor(), new UpdateEquipmentCommand(
                $equipmentId,
                (string) $this->request->getPost('codigo'),
                $this->nullableString($this->request->getPost('patente')),
                $this->nullableString($this->request->getPost('observaciones')),
                $this->nullableInt($this->request->getPost('marca_id')),
                $this->nullableInt($this->request->getPost('modelo_id')),
                $this->nullableInt($this->request->getPost('anio')),
                $this->nullableString($this->request->getPost('chasis')),
                $this->nullableString($this->request->getPost('motor')),
            ));

            return $this->success($equipmentId, "Equipo {$result->code} actualizado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function transfer(int $equipmentId): RedirectResponse
    {
        try {
            $result = $this->transferHandler()->execute($this->actor(), new TransferEquipmentCommand(
                $equipmentId,
                (int) $this->request->getPost('sucursal_destino_id'),
                $this->dateTime((string) $this->request->getPost('fecha_traslado'), 'La fecha de traslado no es válida.'),
                (string) $this->request->getPost('motivo'),
            ));

            return $this->success($equipmentId, "Equipo {$result->code} trasladado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function decommission(int $equipmentId): RedirectResponse
    {
        try {
            $result = $this->decommissionHandler()->execute($this->actor(), new DecommissionEquipmentCommand(
                $equipmentId,
                $this->dateTime((string) $this->request->getPost('fecha_baja'), 'La fecha de baja no es válida.'),
            ));

            return $this->success($equipmentId, "Equipo {$result->code} dado de baja; su historial se conserva.");
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    public function correctReading(int $equipmentId, int $readingId): RedirectResponse
    {
        try {
            $result = $this->correctReadingHandler()->execute($this->actor(), new CorrectReadingCommand(
                $equipmentId,
                $readingId,
                $this->nullableInt($this->request->getPost('kilometraje')),
                $this->nullableString($this->request->getPost('horometro')),
                (string) $this->request->getPost('motivo'),
                new DateTimeImmutable(),
                $this->nullableString($this->request->getPost('observaciones')),
            ));

            return $this->success(
                $equipmentId,
                "Lectura {$result->originalReadingId} corregida por la lectura {$result->correctionReadingId}; valores actuales recalculados.",
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->equipmentUrl($equipmentId));
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function details(): GetEquipmentDetails { return service('equipmentDetails'); }
    private function history(): ListReadingHistoryHandler { return service('readingHistory'); }
    private function updateHandler(): UpdateEquipmentHandler { return service('updateEquipment'); }
    private function transferHandler(): TransferEquipmentHandler { return service('transferEquipment'); }
    private function decommissionHandler(): DecommissionEquipmentHandler { return service('decommissionEquipment'); }
    private function correctReadingHandler(): CorrectReadingHandler { return service('correctReading'); }
    private function assetCatalog(): AssetCatalogService { return service('assetCatalog'); }
    private function equipmentList(): ListEquipment { return service('equipmentList'); }
    private function createRelationHandler(): CreateEquipmentRelationHandler { return service('createEquipmentRelation'); }
    private function finishRelationHandler(): FinishEquipmentRelationHandler { return service('finishEquipmentRelation'); }
    private function uploadAttachmentHandler(): UploadEquipmentAttachmentHandler { return service('uploadEquipmentAttachment'); }
    private function listAttachments(): ListEquipmentAttachmentsHandler { return service('listEquipmentAttachments'); }
    private function downloadAttachmentHandler(): DownloadEquipmentAttachmentHandler { return service('downloadEquipmentAttachment'); }
    private function retireAttachmentHandler(): RetireEquipmentAttachmentHandler { return service('retireEquipmentAttachment'); }

    private function success(int $equipmentId, string $message): RedirectResponse
    {
        return redirect()->to($this->equipmentUrl($equipmentId))->with('success', $message);
    }

    private function failure(Throwable $exception, string $target): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de equipo: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($target)->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación.',
        );
    }

    private function equipmentUrl(int $equipmentId): string
    {
        return '/mantenimiento/equipos/' . $equipmentId;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Se recibió un número entero inválido.');
        }

        return (int) $value;
    }

    private function dateTime(string $value, string $message): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException($message);
        }

        return $date;
    }

    private function dateTimeValue(string $value, string $message): DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, trim($value));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        throw new DomainException($message);
    }
}
