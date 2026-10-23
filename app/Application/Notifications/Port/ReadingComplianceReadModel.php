<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use DateTimeImmutable;

/**
 * Lectura del universo de equipos con lectura de kilometraje vencida.
 *
 * Solo equipos activos que controlan kilometraje: un semirremolque sin control
 * de km no tiene lectura que informar y contarlo seria un falso positivo.
 */
interface ReadingComplianceReadModel
{
    /**
     * Equipos activos con control de kilometraje cuya ultima lectura vigente
     * es anterior al corte, o que nunca registraron lectura.
     *
     * @param DateTimeImmutable $cutoff fecha desde la cual la lectura se considera vencida
     * @param int $limit maximo de equipos devueltos
     *
     * @return list<array{
     *     equipmentId:int,
     *     code:string,
     *     plate:string,
     *     driver:string,
     *     lastReadingAt:?string,
     *     lastKm:?int
     * }>
     */
    public function staleReadings(int $companyId, DateTimeImmutable $cutoff, int $limit = 25): array;
}
