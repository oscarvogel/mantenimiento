<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

interface ExpirationDetailReadModel
{
    /**
     * Devuelve una representacion minima del vencimiento (id, tipo, sujeto,
     * fecha vigente, empresa) restringida al empresaId. Devuelve null si no
     * existe o si pertenece a otra empresa.
     *
     * @return array{
     *     id:int,
     *     empresa_id:int,
     *     tipo_vencimiento_id:int,
     *     tipo_nombre:string,
     *     sujeto_tipo:string,
     *     subject_id:int,
     *     subject_name:?string,
     *     fecha_vencimiento:string,
     *     fecha_emision:?string,
     *     numero_documento:?string,
     *     observaciones:?string,
     *     requiere_documento:bool,
     *     dias_aviso_previo:int,
     *     activo:bool
     * }|null
     */
    public function summary(int $companyId, int $expirationId): ?array;
}
