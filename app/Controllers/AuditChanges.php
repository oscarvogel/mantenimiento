<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class AuditChanges extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $actor = (new SessionActorContext())->current();
            if ($actor === null) {
                throw new DomainException('No existe un contexto autenticado válido.');
            }

            $filters = [
                'companyId'   => $this->request->getGet('companyId'),
                'branchId'    => $this->request->getGet('branchId'),
                'actorUserId' => $this->request->getGet('actorUserId'),
                'action'      => $this->request->getGet('action'),
                'entity'      => $this->request->getGet('entity'),
                'entityId'    => $this->request->getGet('entityId'),
                'dateFrom'    => $this->request->getGet('dateFrom'),
                'dateTo'      => $this->request->getGet('dateTo'),
                'search'      => $this->request->getGet('q'),
            ];

            $page = max(1, (int) ($this->request->getGet('page') ?? 1));
            $perPage = max(1, min(100, (int) ($this->request->getGet('perPage') ?? 25)));

            $result = service('getAuditTrail')->execute($actor, $filters, $page, $perPage);

            return $this->response->setJSON([
                'data' => $result['items'],
                'pagination' => [
                    'page'    => $result['page'],
                    'perPage' => $result['per_page'],
                    'total'   => $result['total'],
                    'pages'   => $result['pages'],
                ],
            ]);
        } catch (DomainException $exception) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            log_message('error', 'Audit changes list error: ' . $exception->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'No se pudo consultar la auditoría de cambios.']);
        }
    }
}
