<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use App\Application\CustomerAccounts\ReceiptFilters;

interface CustomerReceiptReadModel
{
    /**
     * Listado paginado con búsqueda por cliente, número y referencia, y filtros
     * por fecha, estado, medio y moneda.
     *
     * @param list<int>|null $branchIds
     * @return array{
     *     rows:list<array<string,mixed>>,
     *     total:int,
     *     totals:array{total_ars:float,acreditado_ars:float,pendiente_ars:float},
     *     pagination:array{page:int,perPage:int,total:int,totalPages:int}
     * }
     */
    public function list(int $companyId, ?array $branchIds, ReceiptFilters $filters): array;

    /**
     * Detalle completo: cabecera, empresa, cliente, medios con cheque,
     * imputaciones, adjuntos y saldos anterior/posterior.
     *
     * @param list<int>|null $branchIds
     * @return array<string,mixed>|null
     */
    public function detail(int $companyId, ?array $branchIds, int $receiptId): ?array;

    /**
     * Datos para el formulario: cliente, sucursales habilitadas y documentos
     * pendientes de cobro del cliente.
     *
     * @param list<int>|null $branchIds
     * @return array<string,mixed>|null
     */
    public function formData(int $companyId, ?array $branchIds, int $customerId): ?array;

    /**
     * Resumen de cobranza del cliente para su estado de cuenta: total cobrado,
     * anticipos disponibles, medios pendientes, documentos con estado de
     * cobranza y recibos recientes.
     *
     * @return array<string,mixed>
     */
    public function customerCollection(int $companyId, int $customerId): array;

    /**
     * Recibos confirmados del cliente con crédito disponible para imputar.
     *
     * @return list<array<string,mixed>>
     */
    public function availableAdvances(int $companyId, int $customerId): array;

    /** @return array<string,mixed>|null */
    public function attachment(int $companyId, int $receiptId, int $attachmentId): ?array;
}
