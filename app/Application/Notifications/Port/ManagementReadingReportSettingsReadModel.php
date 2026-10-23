<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

/**
 * Configuracion del informe de lecturas atrasadas por empresa.
 *
 * La lectura de la configuracion vive en Infrastructure; la decision de si el
 * informe corresponde (habilitado, hora vencida, equipos atrasados) es del caso
 * de uso, que es quien conoce el reloj y el puerto de lecturas.
 */
interface ManagementReadingReportSettingsReadModel
{
    /**
     * Empresas activas con la configuracion del informe de lecturas.
     *
     * @return list<array{
     *     companyId:int,
     *     companyName:string,
     *     locale:string,
     *     phone:?string,
     *     enabled:bool,
     *     hour:string,
     *     thresholdDays:int,
     *     whatsappInstanceId:string
     * }>
     */
    public function configuredCompanies(): array;
}
