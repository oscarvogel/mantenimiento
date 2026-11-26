<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\CustomerAccounts\ListCustomerAccounts;
use App\Application\CustomerAccounts\FormalizeLegChargeCommand;
use App\Application\CustomerAccounts\PaymentImputationInput;
use App\Application\CustomerAccounts\RegisterCustomerPaymentCommand;
use App\Application\CustomerAccounts\RegisterOpeningBalanceCommand;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Cuenta corriente de clientes (#530): control de cierre comercial de tramos y
 * estado de cuenta en ARS. Traduce la petición y delega en los casos de uso;
 * la autorización y el scope se validan allí.
 */
final class CustomerAccounts extends BaseController
{
    private const QUEUE = '/viajes/cierre-comercial';
    private const ACCOUNTS = '/clientes/cuenta-corriente';

    public function closingQueue(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getCommercialClosingQueue')->execute(
                $actor,
                strtoupper(trim((string) $this->request->getGet('estado'))) ?: 'PENDIENTE',
                $this->request->getGet('q') === null ? null : (string) $this->request->getGet('q'),
            );
            $payload['links'] = $this->links();
            $payload['returnTo'] = self::QUEUE . '?estado=' . $payload['status'];

            return $this->renderApp($actor, 'trips-commercial-closing', 'trips-commercial-closing', 'Cierre comercial', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el control de cierre comercial.', '/viajes/operativos');
        }
    }

    public function formalize(int $legId): RedirectResponse
    {
        try {
            $command = new FormalizeLegChargeCommand(
                $legId,
                strtoupper(trim((string) $this->request->getPost('modalidad'))),
                $this->requiredDate('documento_fecha', 'La fecha del documento'),
                $this->text('documento_numero', 60),
                $this->text('documento_tipo', 20),
                $this->requiredAmount('importe'),
                $this->currency(),
                $this->optionalRate(),
                $this->text('observaciones', 500),
            );
            service('formalizeLegCharge')->execute($this->actor(), $command);

            return redirect()->to($this->back())->with('success', 'El cobro del tramo quedó formalizado y se registró el cargo en la cuenta corriente del cliente.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo formalizar el cobro del tramo.', $this->back());
        }
    }

    public function markNonCollectible(int $legId): RedirectResponse
    {
        try {
            service('markLegNonCollectible')->execute($this->actor(), $legId, (string) $this->request->getPost('motivo'));

            return redirect()->to($this->back())->with('success', 'El tramo quedó cerrado como no cobrable. No se generó deuda.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo marcar el tramo como no cobrable.', $this->back());
        }
    }

    public function reopenNonCollectible(int $legId): RedirectResponse
    {
        try {
            service('reopenNonCollectibleLeg')->execute($this->actor(), $legId, (string) $this->request->getPost('motivo'));

            return redirect()->to($this->back())->with('success', 'El tramo se reabrió y volvió a la cola del cierre comercial.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo reabrir el tramo.', $this->back());
        }
    }

    public function annul(int $chargeId): RedirectResponse
    {
        try {
            service('annulCustomerCharge')->execute($this->actor(), $chargeId, (string) $this->request->getPost('motivo'));

            return redirect()->to($this->back())->with('success', 'El cargo quedó anulado con su contraasiento. Si era de un tramo, volvió a pendientes.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo anular el cargo.', $this->back());
        }
    }

    public function accounts(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('listCustomerAccounts')->execute(
                $actor,
                $this->request->getGet('q') === null ? null : (string) $this->request->getGet('q'),
                $this->request->getGet('moneda') === null ? null : (string) $this->request->getGet('moneda'),
                $this->page(),
                $this->perPage(),
            );
            $payload['links'] = $this->links();
            $payload['returnTo'] = self::ACCOUNTS;

            return $this->renderApp($actor, 'customer-accounts', 'customer-accounts', 'Cuenta corriente de clientes', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar la cuenta corriente de clientes.', '/viajes/operativos');
        }
    }

    /**
     * Registra un cobro del cliente, con las imputaciones a documentos de la
     * misma moneda (#588).
     */
    public function registerPayment(int $customerId): RedirectResponse
    {
        $location = self::ACCOUNTS . '/' . $customerId;
        try {
            $imputations = [];
            $chargeIds = (array) ($this->request->getPost('cargo_id') ?? []);
            $amounts = (array) ($this->request->getPost('importe_cargo') ?? []);
            foreach ($chargeIds as $index => $chargeId) {
                $amount = trim((string) ($amounts[$index] ?? ''));
                if ($chargeId === '' || $amount === '') {
                    continue;
                }
                $imputations[] = new PaymentImputationInput((int) $chargeId, $amount);
            }

            $command = new RegisterCustomerPaymentCommand(
                $customerId,
                $this->requiredDate('fecha', 'La fecha del cobro'),
                strtoupper(trim((string) $this->request->getPost('moneda'))),
                $this->requiredAmount('importe'),
                $this->optionalRate(),
                strtoupper(trim((string) $this->request->getPost('medio'))),
                $this->text('referencia', 100),
                $this->text('observaciones', 500),
                $this->request->getPost('diferido') !== null,
                $imputations,
            );
            service('registerCustomerPayment')->execute($this->actor(), $command);

            return redirect()->to($location)->with('success', 'El cobro quedó registrado en la cuenta corriente del cliente.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar el cobro.', $location);
        }
    }

    /** Acredita un cobro diferido: recién ahí impacta en el libro (#588). */
    public function creditPayment(int $paymentId): RedirectResponse
    {
        try {
            service('creditCustomerPayment')->execute($this->actor(), $paymentId);

            return redirect()->to($this->back())->with('success', 'El cobro quedó acreditado y impactó en la cuenta corriente.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo acreditar el cobro.', $this->back());
        }
    }

    /** Anula un cobro: restituye la deuda con contraasiento (#588). */
    public function annulPayment(int $paymentId): RedirectResponse
    {
        try {
            service('annulCustomerPayment')->execute($this->actor(), $paymentId, (string) $this->request->getPost('motivo'));

            return redirect()->to($this->back())->with('success', 'El cobro quedó anulado y se restituyó la deuda del cliente.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo anular el cobro.', $this->back());
        }
    }

    public function statement(int $customerId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getCustomerStatement')->execute($actor, $customerId);
            $payload['links'] = $this->links() + [
                'openingBalance' => base_url('clientes/cuenta-corriente/' . $customerId . '/saldo-inicial'),
            ];
            $payload['returnTo'] = self::ACCOUNTS . '/' . $customerId;

            return $this->renderApp($actor, 'customer-account-detail', 'customer-account-detail', 'Cuenta corriente · ' . $payload['customer']['nombre'], $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el estado de cuenta del cliente.', self::ACCOUNTS);
        }
    }

    public function openingBalance(int $customerId): RedirectResponse
    {
        $location = self::ACCOUNTS . '/' . $customerId;
        try {
            $command = new RegisterOpeningBalanceCommand(
                $customerId,
                strtoupper(trim((string) $this->request->getPost('sentido'))),
                $this->requiredDate('fecha', 'La fecha del saldo inicial'),
                $this->requiredAmount('importe'),
                $this->currency(),
                $this->optionalRate(),
                $this->text('referencia', 60),
                $this->text('observaciones', 500),
            );
            service('registerOpeningBalance')->execute($this->actor(), $command);

            return redirect()->to($location)->with('success', 'El saldo inicial del cliente quedó registrado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar el saldo inicial.', $location);
        }
    }

    /** @return array<string,string> */
    private function links(): array
    {
        return [
            'queue' => base_url('viajes/cierre-comercial'),
            'accounts' => base_url('clientes/cuenta-corriente'),
            // Familias que el frontend completa con el id (terminan en barra).
            'legsBase' => base_url('viajes/cierre-comercial/tramos/'),
            'chargesBase' => base_url('clientes/cuenta-corriente/cargos/'),
            'paymentsBase' => base_url('clientes/cuenta-corriente/cobranzas/'),
            'tripsBase' => base_url('viajes/operativos'),
        ];
    }

    /** Página pedida, con el mismo patrón de Viajes (#532). */
    private function page(): int
    {
        $page = (int) ($this->request->getGet('pagina') ?? 1);

        return $page > 0 ? $page : 1;
    }

    private function perPage(): int
    {
        $perPage = (int) ($this->request->getGet('por_pagina') ?? ListCustomerAccounts::DEFAULT_PER_PAGE);

        return in_array($perPage, ListCustomerAccounts::PER_PAGE_OPTIONS, true) ? $perPage : ListCustomerAccounts::DEFAULT_PER_PAGE;
    }


    /** Vuelve a la pantalla desde la que se operó, siempre dentro de la app. */
    private function back(): string
    {
        $raw = trim((string) $this->request->getPost('volver'));
        if ($raw !== '' && preg_match('#^/(viajes/cierre-comercial|clientes/cuenta-corriente)(/[0-9]+)?(\?[A-Za-z0-9_=&%+.\-]*)?$#', $raw) === 1) {
            return $raw;
        }

        return self::QUEUE;
    }

    private function requiredDate(string $key, string $label): DateTimeImmutable
    {
        $raw = trim((string) $this->request->getPost($key));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException($label . ' no es válida.');
        }

        return $date;
    }

    private function requiredAmount(string $key): float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost($key)));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('El importe debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    private function currency(): string
    {
        $currency = strtoupper(trim((string) $this->request->getPost('moneda')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda no es válida.');
        }

        return $currency;
    }

    private function optionalRate(): ?float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost('cotizacion')));
        if ($raw === '') {
            return null;
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

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('Tu sesión no está disponible.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, string $fallback, string $location): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una operación de cuenta corriente de clientes: {message}', ['message' => $exception->getMessage()]);
        }

        $message = $exception instanceof DomainException
            ? $exception->getMessage()
            : (ENVIRONMENT === 'development' ? $fallback . ' ' . $exception->getMessage() : $fallback);

        return redirect()->to($location)->withInput()->with('error', $message);
    }
}
