<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\AddTripLegCommand;
use App\Application\Trips\CreateTripCommand;
use App\Application\Trips\DeleteOperationalTripCost;
use App\Application\Trips\DeleteOperationalTripCostCommand;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\RegisterTripCashMovementCommand;
use App\Application\Trips\RegisterTripExpenseCommand;
use App\Application\Trips\RegisterTripFuelCommand;
use App\Application\Trips\RegisterTripOtherEarningCommand;
use App\Application\Trips\UpdateTripCashMovementCommand;
use App\Application\Trips\UpdateTripExpenseCommand;
use App\Application\Trips\UpdateTripFuelLoadCommand;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\Trips\TripCostEntryForm;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
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

    public function localities(): ResponseInterface
    {
        try {
            $localities = service('searchTripLocalities')->execute(
                $this->actor(),
                (string) $this->request->getGet('q'),
            );

            return $this->response->setJSON(['localities' => $localities]);
        } catch (Throwable $exception) {
            $status = $exception instanceof DomainException ? 403 : 500;
            if ($status === 500) {
                log_message('error', 'No se pudieron buscar localidades para viajes: {message}', ['message' => $exception->getMessage()]);
            }
            return $this->response->setStatusCode($status)->setJSON(['message' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudieron buscar localidades.']);
        }
    }

    public function store(): RedirectResponse
    {
        try {
            $command = CreateTripCommand::fromInput((array) $this->request->getPost());
            $result = service('createTrip')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $result->id)->with(
                'success',
                'El viaje #' . $result->number . ' quedó iniciado y en curso.',
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo iniciar el viaje.', '/viajes/operativos/nuevo');
        }
    }

    public function addLeg(int $tripId): RedirectResponse
    {
        try {
            $command = AddTripLegCommand::fromInput((array) $this->request->getPost() + ['viaje_id' => $tripId]);
            service('addTripLeg')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'El tramo quedó agregado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo agregar el tramo.', '/viajes/operativos/' . $tripId);
        }
    }

    public function show(int $tripId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getOperationalTrip')->execute($actor, $tripId);
            $payload['backUrl'] = base_url('viajes/operativos');
            $entryType = TripCostEntryForm::normalizeType($this->request->getGet('cargar'));
            if ($entryType !== null) {
                $oldInput = $this->request->getGet('error') === '1' ? session('_ci_old_input') : null;
                $oldPost = is_array($oldInput) && is_array($oldInput['post'] ?? null) ? $oldInput['post'] : [];
                $editingId = filter_var($this->request->getGet('movimiento'), FILTER_VALIDATE_INT);
                $payload['entryForm'] = TripCostEntryForm::state(
                    $entryType,
                    $oldPost,
                    (array) $this->request->getGet(),
                    $editingId === false ? null : (int) $editingId,
                );
            }

            return $this->renderApp($actor, 'trips-operations', 'trip-operations-detail', 'Detalle del viaje', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el viaje.');
        }
    }

    public function summaryPdf(int $tripId): ResponseInterface
    {
        try {
            $pdf = service('generateOperationalTripSummaryPdf')->execute($this->actor(), $tripId);

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="resumen-viaje-' . $tripId . '.pdf"')
                ->setHeader('Content-Length', (string) strlen($pdf))
                ->setBody($pdf);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo generar el resumen del viaje.', '/viajes/operativos/' . $tripId);
        }
    }

    public function driverSettlementPdf(int $tripId): ResponseInterface
    {
        try {
            $pdf = service('generateDriverSettlementPdf')->execute($this->actor(), $tripId);

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="liquidacion-chofer-viaje-' . $tripId . '.pdf"')
                ->setHeader('Content-Length', (string) strlen($pdf))
                ->setBody($pdf);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo generar la liquidación del chofer.', '/viajes/operativos/' . $tripId);
        }
    }

    public function start(int $tripId): RedirectResponse
    {
        try {
            $departureAt = $this->optionalDateTime($this->request->getPost('fecha_salida_real'), 'La fecha real de salida');
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

    public function finish(int $tripId): RedirectResponse
    {
        try {
            $returnAt = $this->optionalDateTime($this->request->getPost('fecha_regreso_real'), 'La fecha real de regreso');
            $km = filter_var($this->request->getPost('km_regreso'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($km === false) {
                throw new DomainException('Ingresá el odómetro de regreso del viaje.');
            }

            service('finishTrip')->execute($this->actor(), $tripId, $returnAt, (int) $km);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'El viaje quedó cerrado y se generó la lectura del equipo.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cerrar el viaje.', '/viajes/operativos/' . $tripId);
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

    public function addFuel(int $tripId): RedirectResponse
    {
        try {
            $command = new RegisterTripFuelCommand(
                $tripId,
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('litros'),
                $this->requiredFloat('importe'),
                $this->currency(),
                $this->rate(),
                $this->optionalInt('kilometraje'),
                $this->text('estacion', 120),
                $this->text('comprobante', 80),
                $this->text('observaciones', 500),
                $this->optionalInt('tramo_id'),
            );
            service('registerTripFuel')->execute($this->actor(), $command);

            return $this->afterCostSave(
                $tripId,
                TripCostEntryForm::FUEL,
                'La carga de combustible quedó registrada.',
                (array) $this->request->getPost(),
            );
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo registrar el combustible.', $tripId, TripCostEntryForm::FUEL);
        }
    }

    public function addExpense(int $tripId): RedirectResponse
    {
        try {
            $paidBy = strtoupper(trim((string) $this->request->getPost('pagado_por')));
            if ($paidBy === '') {
                $paidBy = (string) $this->request->getPost('pagado_de_caja') === '1' ? 'CAJA' : 'EMPRESA';
            }
            $command = new RegisterTripExpenseCommand(
                $tripId,
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('importe'),
                $this->optionalInt('categoria_id'),
                $this->currency(),
                $this->rate(),
                $this->optionalInt('medio_pago_id'),
                $paidBy === 'CAJA',
                $this->text('comprobante', 80),
                $this->text('observaciones', 500),
                $this->optionalInt('tramo_id'),
                $paidBy,
            );
            service('registerTripExpense')->execute($this->actor(), $command);

            return $this->afterCostSave(
                $tripId,
                TripCostEntryForm::EXPENSE,
                'El gasto quedó registrado.',
                (array) $this->request->getPost(),
            );
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo registrar el gasto.', $tripId, TripCostEntryForm::EXPENSE);
        }
    }

    public function addOtherEarning(int $tripId): RedirectResponse
    {
        try {
            $command = RegisterTripOtherEarningCommand::fromInput([
                'viaje_id' => $tripId,
                'empleado_id' => $this->request->getPost('empleado_id'),
                'fecha' => $this->request->getPost('fecha'),
                'importe' => $this->request->getPost('importe'),
                'moneda' => $this->request->getPost('moneda'),
                'cotizacion' => $this->request->getPost('cotizacion'),
                'concepto' => $this->request->getPost('concepto'),
                'observaciones' => $this->request->getPost('observaciones'),
            ]);
            service('registerTripOtherEarning')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'El haber del chofer quedó registrado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar el haber del chofer.', '/viajes/operativos/' . $tripId);
        }
    }

    public function addCashMovement(int $tripId): RedirectResponse
    {
        try {
            $command = new RegisterTripCashMovementCommand(
                $tripId,
                strtoupper(trim((string) $this->request->getPost('tipo'))),
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('importe'),
                $this->currency(),
                $this->rate(),
                $this->text('observaciones', 500),
            );
            service('registerTripCashMovement')->execute($this->actor(), $command);

            return $this->afterCostSave(
                $tripId,
                TripCostEntryForm::CASH,
                'El movimiento de caja quedó registrado.',
                (array) $this->request->getPost(),
            );
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo registrar el movimiento de caja.', $tripId, TripCostEntryForm::CASH);
        }
    }

    public function updateFuel(int $tripId, int $movementId): RedirectResponse
    {
        try {
            $command = new UpdateTripFuelLoadCommand(
                $tripId,
                $movementId,
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('litros'),
                $this->requiredFloat('importe'),
                $this->currency(),
                $this->rate(),
                $this->optionalInt('kilometraje'),
                $this->text('estacion', 120),
                $this->text('comprobante', 80),
                $this->text('observaciones', 500),
                $this->optionalInt('tramo_id'),
            );
            service('updateTripFuelLoad')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'Se actualizaron los datos de combustible.');
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo editar la carga de combustible.', $tripId, TripCostEntryForm::FUEL, $movementId);
        }
    }

    public function updateExpense(int $tripId, int $movementId): RedirectResponse
    {
        try {
            $paidBy = strtoupper(trim((string) $this->request->getPost('pagado_por')));
            if ($paidBy === '') {
                $paidBy = (string) $this->request->getPost('pagado_de_caja') === '1' ? 'CAJA' : 'EMPRESA';
            }
            $command = new UpdateTripExpenseCommand(
                $tripId,
                $movementId,
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('importe'),
                $this->optionalInt('categoria_id'),
                $this->currency(),
                $this->rate(),
                $this->optionalInt('medio_pago_id'),
                $this->optionalInt('tramo_id'),
                $paidBy,
                $this->text('comprobante', 80),
                $this->text('observaciones', 500),
            );
            service('updateTripExpense')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'Se actualizaron los datos del gasto.');
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo editar el gasto.', $tripId, TripCostEntryForm::EXPENSE, $movementId);
        }
    }

    public function updateCashMovement(int $tripId, int $movementId): RedirectResponse
    {
        try {
            $command = new UpdateTripCashMovementCommand(
                $tripId,
                $movementId,
                strtoupper(trim((string) $this->request->getPost('tipo'))),
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('importe'),
                $this->currency(),
                $this->rate(),
                $this->text('observaciones', 500),
            );
            service('updateTripCashMovement')->execute($this->actor(), $command);

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', 'Se actualizaron los datos del movimiento de caja.');
        } catch (Throwable $exception) {
            return $this->costFailure($exception, 'No se pudo editar el movimiento de caja.', $tripId, TripCostEntryForm::CASH, $movementId);
        }
    }

    public function deleteFuel(int $tripId, int $movementId): RedirectResponse
    {
        return $this->deleteCostMovement($tripId, $movementId, DeleteOperationalTripCost::FUEL, 'La carga de combustible se quitó de los totales del viaje.');
    }

    public function deleteExpense(int $tripId, int $movementId): RedirectResponse
    {
        return $this->deleteCostMovement($tripId, $movementId, DeleteOperationalTripCost::EXPENSE, 'El gasto se quitó de los totales del viaje.');
    }

    public function deleteCashMovement(int $tripId, int $movementId): RedirectResponse
    {
        return $this->deleteCostMovement($tripId, $movementId, DeleteOperationalTripCost::CASH, 'El movimiento se quitó del saldo de caja del viaje.');
    }

    private function deleteCostMovement(int $tripId, int $movementId, string $type, string $successMessage): RedirectResponse
    {
        try {
            service('deleteOperationalTripCost')->execute(
                $this->actor(),
                new DeleteOperationalTripCostCommand($tripId, $movementId, $type),
            );

            return redirect()->to('/viajes/operativos/' . $tripId)->with('success', $successMessage);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo quitar el movimiento del viaje.', '/viajes/operativos/' . $tripId);
        }
    }

    /** @param array<string,mixed> $post */
    private function afterCostSave(int $tripId, string $type, string $message, array $post): RedirectResponse
    {
        $url = '/viajes/operativos/' . $tripId;
        if (($post['accion'] ?? '') === 'otro') {
            $query = ['cargar' => $type] + TripCostEntryForm::repeatQuery($type, $post);
            $url .= '?' . http_build_query($query);
        }

        return redirect()->to($url)->with('success', $message);
    }

    private function costFailure(Throwable $exception, string $fallback, int $tripId, string $type, ?int $movementId = null): RedirectResponse
    {
        $location = '/viajes/operativos/' . $tripId . '?cargar=' . rawurlencode($type) . '&error=1';
        if ($movementId !== null) {
            $location .= '&movimiento=' . $movementId;
        }

        return $this->failure($exception, $fallback, $location);
    }

    private function requiredDateTime(string $key): DateTimeImmutable
    {
        $value = $this->optionalDateTime($this->request->getPost($key), 'La fecha del movimiento');
        if ($value === null) {
            throw new DomainException('Indicá la fecha del movimiento.');
        }

        return $value;
    }

    private function requiredFloat(string $key): float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost($key)));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('Ingresá un importe o cantidad mayor que cero.');
        }

        return (float) $raw;
    }

    private function optionalInt(string $key): ?int
    {
        $raw = trim((string) $this->request->getPost($key));
        if ($raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    private function currency(): string
    {
        $currency = strtoupper(trim((string) $this->request->getPost('moneda')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda no es válida.');
        }

        return $currency;
    }

    private function rate(): float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost('cotizacion')));
        if ($raw === '') {
            return 1.0;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    private function text(string $key, int $max): ?string
    {
        $value = trim((string) $this->request->getPost($key));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function optionalDateTime(mixed $value, string $label = 'La fecha real de salida'): ?DateTimeImmutable
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

        throw new DomainException($label . ' no es válida.');
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
