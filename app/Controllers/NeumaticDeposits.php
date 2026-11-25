<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\RegisterDepositMovementCommand;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Depósito de neumáticos: existencias y movimientos.
 *
 * Traduce la request a `GetNeumaticDeposit` y `RegisterDepositMovement`; las
 * reglas de existencia viven en el dominio y el scope en el caso de uso.
 */
final class NeumaticDeposits extends BaseController
{
    private const BASE = 'neumaticos/deposito';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $raw = trim((string) $this->request->getGet('deposito'));
            $payload = service('neumaticDeposit')->execute($actor, ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null);
            foreach (['stock', 'movements'] as $list) {
                foreach ($payload[$list] as &$row) {
                    $row['tireUrl'] = base_url('neumaticos/' . (int) $row['neumaticId']);
                }
                unset($row);
            }
            $payload['urls'] = [
                'index' => base_url(self::BASE),
                'movement' => base_url(self::BASE . '/movimientos'),
                'masters' => base_url('neumaticos/maestros') . '#depositos',
                'registry' => base_url('neumaticos'),
            ];
            $payload['oldInput'] = [];
            foreach (['tipo', 'deposito_id', 'neumatico_id', 'cantidad', 'numero_planilla', 'observaciones', 'fecha'] as $field) {
                $value = old($field);
                if (is_string($value) && $value !== '') {
                    $payload['oldInput'][$field] = $value;
                }
            }

            return $this->renderApp($actor, 'tire-deposit', 'tire-deposit', 'Depósito de neumáticos', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url('neumaticos'));
        }
    }

    public function movement(): RedirectResponse
    {
        $depositId = trim((string) $this->request->getPost('deposito_id'));
        $back = base_url(self::BASE) . (ctype_digit($depositId) ? '?deposito=' . $depositId : '');
        try {
            $quantity = trim((string) $this->request->getPost('cantidad'));
            if (preg_match('/^-?\d+$/', $quantity) !== 1) {
                throw new DomainException('Indicá la cantidad como un número entero.');
            }
            $quantity = (int) $quantity;
            $type = mb_strtoupper(trim((string) $this->request->getPost('tipo')));

            $stock = service('registerNeumaticDepositMovement')->execute($this->actor(), new RegisterDepositMovementCommand(
                $type,
                ctype_digit($depositId) ? (int) $depositId : 0,
                $this->positiveInt('neumatico_id'),
                $quantity,
                trim((string) $this->request->getPost('numero_planilla')),
                trim((string) $this->request->getPost('observaciones')),
                $this->optionalDateTime('fecha'),
            ));

            return redirect()->to($back)->with('success', sprintf('El movimiento quedó registrado. Existencia en el depósito: %d.', $stock));
        } catch (Throwable $exception) {
            return $this->failure($exception, $back . '#movimiento');
        }
    }

    private function positiveInt(string $field): int
    {
        $value = trim((string) $this->request->getPost($field));

        return ctype_digit($value) ? (int) $value : 0;
    }

    private function optionalDateTime(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new DomainException('La fecha y hora no son válidas.');
        }

        return $date;
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El depósito de neumáticos requiere una cuenta de empresa.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, string $back): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una operación del depósito de neumáticos: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($back)->withInput()->with('error', $exception instanceof DomainException
            ? $exception->getMessage()
            : 'No se pudo registrar el movimiento del depósito.');
    }
}
