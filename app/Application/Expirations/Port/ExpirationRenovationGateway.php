<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Application\Expirations\RenovarVencimientoCommand;
use DateTimeImmutable;

/**
 * Unit of Work transaccional para la renovacion de un vencimiento.
 *
 * Encapsula:
 *  - el lock sobre la fila del vencimiento con WHERE empresa_id = ? (escopo
 *    obligatorio, no se puede saltar),
 *  - el insert de la evidencia cuando el command trae adjunto,
 *  - el insert append-only del historial,
 *  - el UPDATE de vencimientos.fecha_vencimiento,
 *  - el rollback completo si algo falla.
 *
 * Si el (empresa_id, id) no existe, devuelve null. Si la fecha nueva no es
 * estrictamente mayor a la actual, tira DomainException para que el caso
 * de uso lo propague al controller.
 */
interface ExpirationRenovationGateway
{
    /**
     * @return array{
     *     expirationId:int,
     *     evidenceId:?int,
     *     renovationId:int,
     *     previousDate:string,
     *     newDate:string,
     *     renovatedAt:string,
     *     userId:?int,
     *     notes:?string,
     *     typeId:int,
     *     subjectType:string,
     *     subjectId:int
     * }|null
     */
    public function renew(
        int $companyId,
        RenovarVencimientoCommand $command,
        DateTimeImmutable $now,
        int $actorUserId,
    ): ?array;
}
