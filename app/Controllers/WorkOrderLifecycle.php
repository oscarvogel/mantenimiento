<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\ChangeWorkOrderState;
use App\Application\WorkOrders\ChangeWorkOrderStateCommand;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class WorkOrderLifecycle extends BaseController
{
    public function waitForParts(int $orderId): RedirectResponse
    {
        return $this->change($orderId, 'esperar_repuestos', 'Orden puesta en espera de repuestos.');
    }

    public function resume(int $orderId): RedirectResponse
    {
        return $this->change($orderId, 'reanudar', 'Orden reanudada correctamente.');
    }

    public function cancel(int $orderId): RedirectResponse
    {
        return $this->change($orderId, 'cancelar', 'Orden cancelada correctamente.');
    }

    private function change(int $orderId, string $action, string $success): RedirectResponse
    {
        try {
            $this->handler()->execute($this->actor(), new ChangeWorkOrderStateCommand(
                $orderId,
                $action,
                $this->nullableString($this->request->getPost('motivo')),
            ));

            return redirect()->to('/mantenimiento')->with('success', $success);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la transición de OT: {message}', ['message' => $exception->getMessage()]);
            }

            return redirect()->to('/mantenimiento')->withInput()->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo cambiar el estado de la orden.',
            );
        }
    }

    private function handler(): ChangeWorkOrderState
    {
        $db = db_connect();

        return new ChangeWorkOrderState(
            new CodeIgniterWorkOrderRepository($db),
            new CodeIgniterWorkOrderTransaction($db),
            new SystemClock(),
        );
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
