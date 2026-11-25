<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\CustomerAccounts\CreateCustomerReceiptCommand;
use App\Application\CustomerAccounts\CustomerReceiptAccess;
use App\Application\CustomerAccounts\ImputeCustomerPaymentCommand;
use App\Application\CustomerAccounts\ReceiptFilters;
use App\Application\CustomerAccounts\ReceiptImputationInput;
use App\Application\CustomerAccounts\ReceiptInstrumentInput;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\PageSize;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Recibos y cobranzas de clientes (#536): listado, formulario multimoneda,
 * confirmación, imputaciones, anticipos, acreditación de medios, anulación,
 * adjuntos e impresión. La autorización se valida en los casos de uso.
 */
final class CustomerReceipts extends BaseController
{
    private const RECEIPTS = '/clientes/recibos';

    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = new ReceiptFilters(
                search: $this->get('q'),
                status: $this->get('estado'),
                medium: $this->get('medio'),
                currency: $this->get('moneda'),
                from: $this->get('desde'),
                to: $this->get('hasta'),
                page: max(1, (int) ($this->request->getGet('page') ?? 1)),
                perPage: PageSize::normalize($this->request->getGet('por_pagina')),
            );
            $payload = service('listCustomerReceipts')->execute($actor, $filters);
            $payload['links'] = $this->links();
            $payload['returnTo'] = self::RECEIPTS . '?' . http_build_query(array_filter($filters->toQuery(), static fn ($value): bool => $value !== null));
            $payload['pagination'] = $this->pagination($payload['pagination'], $filters);

            return $this->renderApp($actor, 'customer-receipts', 'customer-receipts', 'Recibos de cobranza', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudieron cargar los recibos de cobranza.', '/clientes/cuenta-corriente');
        }
    }

    public function create(int $customerId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getCustomerReceiptForm')->execute($actor, $customerId);
            $payload['links'] = $this->links();
            $payload['returnTo'] = '/clientes/cuenta-corriente/' . $customerId;

            return $this->renderApp($actor, 'customer-receipt-form', 'customer-receipt-form', 'Nuevo recibo de cobranza', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo preparar el recibo de cobranza.', '/clientes/cuenta-corriente');
        }
    }

    public function store(): RedirectResponse
    {
        $location = '/clientes/cuenta-corriente';
        try {
            $customerId = (int) $this->request->getPost('cliente_id');
            $location = '/clientes/cuenta-corriente/' . $customerId;
            $command = new CreateCustomerReceiptCommand(
                $customerId,
                (int) $this->request->getPost('sucursal_id'),
                (string) $this->request->getPost('serie'),
                $this->requiredDate('fecha_emision', 'La fecha de emisión'),
                $this->requiredDate('fecha_recepcion', 'La fecha de recepción'),
                $this->text('observaciones', 1000),
                $this->instruments(),
                $this->imputations(),
                strtolower(trim((string) $this->request->getPost('accion'))) === 'confirmar',
            );
            $receiptId = service('createCustomerReceipt')->execute($this->actor(), $command);

            return redirect()->to(self::RECEIPTS . '/' . $receiptId)->with(
                'success',
                $command->confirm
                    ? 'El recibo quedó confirmado, numerado y con sus medios acreditados según corresponda.'
                    : 'El recibo quedó guardado en borrador. Confirmalo para numerarlo y contabilizarlo.',
            );
        } catch (Throwable $exception) {
            return $this->failureWithInput($exception, 'No se pudo registrar el recibo de cobranza.', $location);
        }
    }

    public function show(int $receiptId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('getCustomerReceipt')->execute($actor, $receiptId);
            $payload['links'] = $this->links();
            $payload['returnTo'] = self::RECEIPTS . '/' . $receiptId;

            return $this->renderApp(
                $actor,
                'customer-receipt-detail',
                'customer-receipt-detail',
                'Recibo · ' . ($payload['receipt']['numero_visible'] ?? ''),
                $payload,
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el recibo de cobranza.', self::RECEIPTS);
        }
    }

    public function confirm(int $receiptId): RedirectResponse
    {
        return $this->action(
            static fn (ActorContext $actor) => service('confirmCustomerReceipt')->execute($actor, $receiptId),
            'El recibo quedó confirmado y numerado.',
            'No se pudo confirmar el recibo.',
            self::RECEIPTS . '/' . $receiptId,
        );
    }

    public function annul(int $receiptId): RedirectResponse
    {
        $reason = (string) $this->request->getPost('motivo');

        return $this->action(
            static fn (ActorContext $actor) => service('annulCustomerReceipt')->execute($actor, $receiptId, $reason),
            'El recibo quedó anulado con su contraasiento y sus imputaciones fueron revertidas.',
            'No se pudo anular el recibo.',
            self::RECEIPTS . '/' . $receiptId,
        );
    }
    public function discard(int $receiptId): RedirectResponse
    {
        return $this->action(
            static fn (ActorContext $actor) => service('discardCustomerReceiptDraft')->execute($actor, $receiptId),
            'El borrador se descartó. No había asientos contabilizados.',
            'No se pudo descartar el borrador.',
            self::RECEIPTS,
        );
    }

    public function accredit(int $receiptId, int $instrumentId): RedirectResponse
    {
        return $this->action(
            static fn (ActorContext $actor) => service('accreditReceiptInstrument')->execute($actor, $receiptId, $instrumentId),
            'El medio de pago quedó acreditado en la cuenta corriente.',
            'No se pudo acreditar el medio de pago.',
            self::RECEIPTS . '/' . $receiptId,
        );
    }

    public function impute(int $receiptId): RedirectResponse
    {
        try {
            $command = new ImputeCustomerPaymentCommand($receiptId, $this->imputations());
            service('imputeCustomerPayment')->execute($this->actor(), $command);

            return redirect()->to(self::RECEIPTS . '/' . $receiptId)->with('success', 'El pago quedó imputado a los documentos elegidos.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo imputar el pago.', self::RECEIPTS . '/' . $receiptId);
        }
    }

    public function uploadAttachment(int $receiptId): RedirectResponse
    {
        try {
            $file = $this->request->getFile('archivo');
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('Seleccioná un comprobante válido para adjuntar.');
            }
            $instrumentId = (int) $this->request->getPost('recibo_medio_id');
            service('uploadReceiptAttachment')->execute(
                $this->actor(),
                $receiptId,
                $instrumentId > 0 ? $instrumentId : null,
                $file->getTempName(),
                $file->getClientName(),
                $this->text('descripcion', 255),
            );

            return redirect()->to(self::RECEIPTS . '/' . $receiptId)->with('success', 'El comprobante quedó guardado en el almacenamiento privado.');
        } catch (Throwable $exception) {
            return $this->failureWithInput($exception, 'No se pudo adjuntar el comprobante.', self::RECEIPTS . '/' . $receiptId);
        }
    }

    public function downloadAttachment(int $receiptId, int $attachmentId): ResponseInterface|RedirectResponse
    {
        try {
            $download = service('downloadReceiptAttachment')->execute($this->actor(), $receiptId, $attachmentId);

            return $this->response
                ->download($download['name'], $download['content'], false)
                ->setContentType($download['mime_type'])
                ->setHeader('X-Content-Type-Options', 'nosniff');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo descargar el comprobante.', self::RECEIPTS . '/' . $receiptId);
        }
    }

    public function print(int $receiptId): ResponseInterface|RedirectResponse
    {
        try {
            $pdf = service('generateCustomerReceiptPdf')->execute($this->actor(), $receiptId);

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $pdf['filename'] . '"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($pdf['content']);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo generar el recibo imprimible.', self::RECEIPTS . '/' . $receiptId);
        }
    }

    /** @return list<ReceiptInstrumentInput> */
    private function instruments(): array
    {
        $raw = $this->request->getPost('medios');
        if (! is_array($raw) || $raw === []) {
            throw new DomainException('Agregá al menos un medio de pago al recibo.');
        }
        $instruments = [];
        foreach (array_values($raw) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kind = strtoupper(trim((string) ($row['medio'] ?? '')));
            $currency = strtoupper(trim((string) ($row['moneda'] ?? 'ARS')));
            $instruments[] = new ReceiptInstrumentInput(
                kind: $kind,
                currency: $currency,
                amount: (string) ($row['importe'] ?? ''),
                rate: isset($row['cotizacion']) && trim((string) $row['cotizacion']) !== '' ? (string) $row['cotizacion'] : null,
                bank: $this->nullable($row['banco'] ?? null),
                account: $this->nullable($row['cuenta_destino'] ?? null),
                holder: $this->nullable($row['titular'] ?? null),
                transferDate: $this->dateOrNull($row['fecha_transferencia'] ?? null),
                reference: $this->nullable($row['referencia'] ?? null),
                verification: strtoupper(trim((string) ($row['estado_verificacion'] ?? 'PENDIENTE'))),
                chequeNumber: $this->nullable($row['cheque_numero'] ?? null),
                drawer: $this->nullable($row['librador'] ?? null),
                drawerTaxId: $this->nullable($row['cuit_librador'] ?? null),
                chequeIssueDate: $this->dateOrNull($row['cheque_fecha_emision'] ?? null),
                chequeDueDate: $this->dateOrNull($row['cheque_fecha_pago'] ?? null),
                chequeReceivedDate: $this->dateOrNull($row['cheque_fecha_recepcion'] ?? null),
                notes: $this->nullable($row['observaciones'] ?? null),
            );
        }
        if ($instruments === []) {
            throw new DomainException('Agregá al menos un medio de pago al recibo.');
        }

        return $instruments;
    }

    /** @return list<ReceiptImputationInput> */
    private function imputations(): array
    {
        $raw = $this->request->getPost('imputaciones');
        if (! is_array($raw)) {
            return [];
        }
        $imputations = [];
        foreach (array_values($raw) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $chargeId = (int) ($row['cargo_id'] ?? 0);
            $amount = trim((string) ($row['importe'] ?? ''));
            if ($chargeId <= 0 || $amount === '') {
                continue;
            }
            $imputations[] = new ReceiptImputationInput($chargeId, $amount);
        }

        return $imputations;
    }

    private function action(callable $operation, string $success, string $failure, string $location): RedirectResponse
    {
        try {
            $operation($this->actor());

            return redirect()->to($location)->with('success', $success);
        } catch (Throwable $exception) {
            return $this->failure($exception, $failure, $location);
        }
    }

    /** @return array<string,string> */
    private function links(): array
    {
        return [
            'receipts' => base_url('clientes/recibos'),
            'newReceiptBase' => base_url('clientes/recibos/nuevo/'),
            'receiptsBase' => base_url('clientes/recibos/'),
            'accounts' => base_url('clientes/cuenta-corriente'),
            'accountsBase' => base_url('clientes/cuenta-corriente/'),
            'tripsBase' => base_url('viajes/operativos'),
        ];
    }

    /** @param array<string,mixed> $pagination @return array<string,mixed> */
    private function pagination(array $pagination, ReceiptFilters $filters): array
    {
        $query = array_filter($filters->toQuery(), static fn ($value): bool => $value !== null);
        $query['por_pagina'] = $filters->perPage;
        $url = static fn (int $page): string => self::RECEIPTS . '?' . http_build_query([...$query, 'page' => $page]);
        $page = (int) ($pagination['page'] ?? 1);
        $totalPages = (int) ($pagination['totalPages'] ?? 1);

        return $pagination + [
            'pageKey' => 'page',
            'perPageKey' => 'por_pagina',
            'previousUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $totalPages ? $url($page + 1) : null,
        ];
    }

    private function get(string $key): ?string
    {
        $value = $this->request->getGet($key);

        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
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

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Una de las fechas del recibo no es válida.');
        }

        return $date;
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
            log_message('error', 'Falló una operación de recibos de cobranza: {message}', ['message' => $exception->getMessage()]);
        }
        $message = $exception instanceof DomainException
            ? $exception->getMessage()
            : (ENVIRONMENT === 'development' ? $fallback . ' ' . $exception->getMessage() : $fallback);

        return redirect()->to($location)->with('error', $message);
    }

    private function failureWithInput(Throwable $exception, string $fallback, string $location): RedirectResponse
    {
        return $this->failure($exception, $fallback, $location)->withInput();
    }
}
