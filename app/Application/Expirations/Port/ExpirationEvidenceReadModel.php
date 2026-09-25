<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

interface ExpirationEvidenceReadModel
{
    /**
     * Devuelve los adjuntos asociados al historial de renovaciones de un
     * vencimiento, restringidos al empresaId del actor. El resultado conserva
     * la ruta privada para que el controller valide tenant antes de servir el
     * archivo.
     *
     * @return list<array{
     *     id:int,
     *     empresa_id:int,
     *     vencimiento_id:int,
     *     nombre_original:string,
     *     mime_type:string,
     *     tamanio:int,
     *     created_at:string,
     *     download_url:string
     * }>
     */
    public function listForExpiration(int $companyId, int $expirationId): array;

    /**
     * Lista de filas crudas para el historial (incluye la fecha anterior y la
     * nueva, el usuario, observaciones y, si existe, la URL de descarga de la
     * evidencia).
     *
     * @return list<array{
     *     id:int,
     *     empresa_id:int,
     *     vencimiento_id:int,
     *     fecha_anterior:string,
     *     fecha_nueva:string,
     *     fecha_renovacion:string,
     *     usuario_id:int|null,
     *     usuario_nombre:string|null,
     *     observaciones:?string,
     *     evidencia: ?array{
     *         id:int,
     *         nombre_original:string,
     *         mime_type:string,
     *         tamanio:int,
     *         created_at:string,
     *         download_url:string
     *     }
     * }>
     */
    public function historyForExpiration(int $companyId, int $expirationId): array;
}
