<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\TripImports\Port\InvoiceReviewReadModel;
use App\Application\TripImports\ReviewTripInvoices;
use App\Application\TripImports\TripImports as TripImportsUseCase;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class TripInvoiceReview extends BaseController
{
    public function index(int $importId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $status = trim((string) $this->request->getGet('estado'));
            $status = $status === '' ? null : $status;
            $page = max(1, (int) $this->request->getGet('page'));
            $payload = $this->useCase()->page($actor, $importId, $status, $page);
            $payload['actions'] = [
                'index' => base_url('superadmin/viajes/importar'),
                'import' => base_url('superadmin/viajes/importaciones/' . $importId),
                'review' => base_url('superadmin/viajes/importaciones/' . $importId . '/facturas'),
                'search' => base_url('superadmin/viajes/importaciones/' . $importId . '/facturas/buscar'),
                'superadmin' => base_url('superadmin'),
            ];
            $payload['statuses'] = [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'SIN_VINCULO', 'label' => 'Sin vínculo'],
                ['value' => 'SUGERIDA', 'label' => 'Sugerida'],
                ['value' => 'AUTO', 'label' => 'Automática'],
                ['value' => 'CONFIRMADO', 'label' => 'Confirmada'],
            ];

            return $this->renderApp($actor, 'superadmin', 'trip-invoice-review', 'Revisar facturas de viajes', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    public function assign(int $importId, int $invoiceId): RedirectResponse
    {
        try {
            $tripId = filter_var($this->request->getPost('viaje_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tripId === false) {
                throw new DomainException('Elegí un viaje válido para vincular la factura.');
            }
            $this->useCase()->assign($this->actor(), $importId, $invoiceId, (int) $tripId);

            return redirect()->to($this->reviewUrl($importId))->with('success', 'Factura vinculada al viaje.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    public function unassign(int $importId, int $invoiceId): RedirectResponse
    {
        try {
            $this->useCase()->unassign($this->actor(), $importId, $invoiceId);

            return redirect()->to($this->reviewUrl($importId))->with('success', 'Vínculo quitado. La factura queda sin viaje.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    public function searchTrips(int $importId): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $import = $this->tripImports()->show($actor, $importId, 1);
            $companyId = (int) ($import['empresa_id'] ?? 0);
            $query = trim((string) $this->request->getGet('q'));
            $trips = service('invoiceReviewReadModel')->trips($companyId, $query);

            return $this->response->setJSON(['trips' => $trips]);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode(400)->setJSON([
                'error' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo buscar viajes.',
            ]);
        }
    }

    private function useCase(): ReviewTripInvoices
    {
        return service('reviewTripInvoices');
    }

    private function tripImports(): TripImportsUseCase
    {
        return service('tripImports');
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            throw new DomainException('La revisión de facturas requiere acceso de Superadministrador.');
        }

        return $actor;
    }

    private function reviewUrl(int $importId): string
    {
        return base_url('superadmin/viajes/importaciones/' . $importId . '/facturas');
    }

    private function failure(Throwable $exception, int $importId): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la revisión de facturas de viajes: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($this->reviewUrl($importId))->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la revisión de facturas.',
        );
    }
}
