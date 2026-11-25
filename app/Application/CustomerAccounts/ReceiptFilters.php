<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

/**
 * Filtros y paginación del listado general de recibos (#536).
 */
final class ReceiptFilters
{
    public const STATUSES = ['BORRADOR', 'CONFIRMADO', 'ANULADO'];
    public const MEDIA = ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'E_CHEQ'];

    public readonly ?string $search;
    public readonly ?string $status;
    public readonly ?string $medium;
    public readonly ?string $currency;
    public readonly ?string $from;
    public readonly ?string $to;
    public readonly int $page;
    public readonly int $perPage;

    public function __construct(
        ?string $search = null,
        ?string $status = null,
        ?string $medium = null,
        ?string $currency = null,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 10,
    ) {
        $search = $search === null ? null : trim($search);
        $status = $status === null ? null : strtoupper(trim($status));
        $medium = $medium === null ? null : strtoupper(trim($medium));
        $currency = $currency === null ? null : strtoupper(trim($currency));

        $this->search = $search === null || $search === '' ? null : mb_substr($search, 0, 120);
        $this->status = in_array($status, self::STATUSES, true) ? $status : null;
        $this->medium = in_array($medium, self::MEDIA, true) ? $medium : null;
        $this->currency = $currency !== null && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
        $this->from = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : null;
        $this->to = $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : null;
        $this->page = max(1, $page);
        $this->perPage = max(1, min(100, $perPage));
    }

    /** @return array<string,string|null> */
    public function toQuery(): array
    {
        return [
            'q' => $this->search,
            'estado' => $this->status,
            'medio' => $this->medium,
            'moneda' => $this->currency,
            'desde' => $this->from,
            'hasta' => $this->to,
        ];
    }
}
