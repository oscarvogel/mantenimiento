<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\CreateTripCommand;
use App\Application\Trips\OperationalTripFilters;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class OperationalTrips extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = OperationalTripFilters::fromQuery($this->request->getGet());
            $requestedPage = filter_var($this->request->getGet('pagina'), FILTER_VALIDATE_INT);
            $page = $requestedPage === false || $requestedPage === null ? 1 : $requestedPage;
            $payload = service('listOperationalTrips')->execute($actor, $filters, $page);

            return $this->renderApp($actor, 'trips-operations', 'trips-operations', 'Viajes operativos', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudieron cargar los viajes operativos.');
        }
    }

    public function create(): string|RedirectResponse
    {
        try {
            $payload = service('tripFormOptions')->execute($this->actor());
            $oldInput = session('_ci_old_input');
            $payload['old'] = is_array($oldInput) && is_array($oldInput['post'] ?? null)
                ? $oldInput['post']
                : [];

            return $this->renderApp($this->actor(), 'trips-operations', 'trip-operations-create', 'Nuevo viaje', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo abrir el alta de viajes.');
        }
    }

    public function store(): RedirectResponse
    {
        try {
            $command = CreateTripCommand::fromInput((array) $this->request->getPost());
            $result = service('createTrip')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $result->id)->with(
                'success',
                'El viaje #' . $result->number . ' quedó planificado.',
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo planificar el viaje.', '/viajes/operativos/nuevo');
        }
    }

    public function show(int $tripId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getOperationalTrip')->execute($actor, $tripId);
            $payload['backUrl'] = base_url('viajes/operativos');

            return $this->renderApp($actor, 'trips-operations', 'trip-operations-detail', 'Detalle del viaje', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el viaje.');
        }
    }

    public function start(int $tripId): RedirectResponse
    {
        try {
            $departureAt = $this->optionalDateTime($this->request->getPost('fecha_salida_real'));
            $km = filter_var($this->request->getPost('km_salida'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($km === false) {
                throw new DomainException('Ingresá el odómetro de salida del viaje.');
            }

            service('startTrip')->execute($this->actor(), $tripId, $departureAt, (int) $km);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'El viaje quedó en curso.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo iniciar el viaje.', '/viajes/operativos/' . $tripId);
        }
    }

    public function cancel(int $tripId): RedirectResponse
    {
        try {
            $reason = trim((string) $this->request->getPost('motivo_cancelacion'));
            service('cancelTrip')->execute($this->actor(), $tripId, $reason);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'El viaje quedó cancelado y conserva su historial.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cancelar el viaje.', '/viajes/operativos/' . $tripId);
        }
    }

    private function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        throw new DomainException('La fecha real de salida no es válida.');
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('Tu sesión no está disponible.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, string $fallback, string $location = '/viajes/operativos'): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una operación de viajes: {message}', ['message' => $exception->getMessage()]);
        }

        $message = $exception instanceof DomainException
            ? $exception->getMessage()
            : (ENVIRONMENT === 'development'
                ? $fallback . ' ' . $exception->getMessage()
                : $fallback);

        return redirect()->to($location)->withInput()->with('error', $message);
    }
}
