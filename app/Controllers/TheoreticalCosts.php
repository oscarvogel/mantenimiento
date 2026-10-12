<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\TheoreticalCost\TheoreticalCostManager;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\TheoreticalCost\BcraReferenceExchangeRate;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class TheoreticalCosts extends BaseController
{
    public function index(): string|ResponseInterface
    {
        try {
            $actor = $this->actor();
            $manager = $this->manager();
            $assets = $manager->assets($actor);
            $base = base_url('gerencial/costo-teorico-km');
            return $this->renderApp($actor, 'theoretical-cost-km', 'theoretical-cost-km', 'Costo teórico por km', [
                'assets' => $assets,
                'canEdit' => $manager->canEdit($actor),
                'urls' => [
                    'page' => $base,
                    'gerencial' => base_url('dashboard'),
                    'assets' => $base . '/activos',
                    'exchangeRate' => $base . '/cotizacion-bcra',
                    'calculate' => $base . '/calcular',
                    'scenarios' => $base . '/escenarios',
                    'save' => $base . '/escenarios',
                    'version' => $base . '/escenarios/{id}/versiones/{version}',
                    'scenarioVersion' => $base . '/escenarios/{id}/versiones/{version}',
                    'export' => $base . '/escenarios/{id}/versiones/{version}/excel',
                    'excel' => $base . '/escenarios/{id}/versiones/{version}/excel',
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode(403)->setBody('No se pudo abrir el costo teórico por km.');
        }
    }

    public function assets(): ResponseInterface
    {
        try {
            return $this->json(['data' => $this->manager()->assets($this->actor())]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function exchangeRate(): ResponseInterface
    {
        try {
            $this->actor();
            return $this->json(['data' => (new BcraReferenceExchangeRate())->latest()]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function calculate(): ResponseInterface
    {
        try {
            $payload = $this->jsonBody();
            return $this->json(['data' => ['resultado' => $this->manager()->calculate($this->actor(), $payload)]]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function scenarios(): ResponseInterface
    {
        try {
            $filters = [
                'tractor_id' => $this->queryString('tractor_id'),
                'semi_id' => $this->queryString('semi_id'),
                'desde' => $this->queryString('desde'),
                'hasta' => $this->queryString('hasta'),
            ];
            return $this->json(['data' => ['items' => $this->manager()->list($this->actor(), $filters)]]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function save(): ResponseInterface
    {
        try {
            $saved = $this->manager()->save($this->actor(), $this->jsonBody());
            return $this->json(['data' => $saved], 201);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function version(int $scenarioId, int $number): ResponseInterface
    {
        try {
            return $this->json(['data' => $this->manager()->version($this->actor(), $scenarioId, $number)]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 404);
        }
    }

    public function export(int $scenarioId, int $number): ResponseInterface
    {
        try {
            $scenario = $this->manager()->version($this->actor(), $scenarioId, $number);
            $contents = service('theoreticalCostXlsxExporter')->export($scenario);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $scenario['nombre']) ?: 'escenario';
            return $this->response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="costo-teorico-' . $safeName . '-v' . (int) $scenario['version'] . '.xlsx"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($contents);
        } catch (Throwable $exception) {
            return $this->failure($exception, 404);
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

    private function manager(): TheoreticalCostManager
    {
        return service('theoreticalCostManager');
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $payload = $this->request->getJSON(true);
        if (! is_array($payload)) {
            throw new DomainException('El cuerpo JSON enviado no es válido.');
        }
        return $payload;
    }

    private function queryString(string $key): ?string
    {
        $value = $this->request->getGet($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($payload + ['csrf' => $this->csrfData()]);
    }

    /** @return array{name:string,hash:string} */
    private function csrfData(): array
    {
        return ['name' => csrf_token(), 'hash' => csrf_hash()];
    }

    private function failure(Throwable $exception, int $domainStatus = 422): ResponseInterface
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló costo teórico por km: {message}', ['message' => $exception->getMessage()]);
        }
        return $this->response->setStatusCode($exception instanceof DomainException ? $domainStatus : 500)->setJSON([
            'error' => [
                'code' => $exception instanceof DomainException ? 'invalid_request' : 'internal_error',
                'message' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación de costo teórico por km.',
            ],
            'csrf' => $this->csrfData(),
        ]);
    }
}
