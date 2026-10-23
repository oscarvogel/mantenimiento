<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\ManagementReadingReportSettingsReadModel;

/**
 * Doble de la configuracion del informe de lecturas por empresa.
 */
final class FakeManagementReadingReportSettings implements ManagementReadingReportSettingsReadModel
{
    /**
     * @param list<array{
     *     companyId:int,
     *     companyName:string,
     *     locale:string,
     *     phone:?string,
     *     enabled:bool,
     *     hour:string,
     *     thresholdDays:int,
     *     whatsappInstanceId:string
     * }> $companies
     */
    public function __construct(private array $companies = [])
    {
    }

    /**
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
    public function configuredCompanies(): array
    {
        return $this->companies;
    }

    /** @param array<string,mixed> $overrides */
    public function withCompany(int $companyId, array $overrides = []): self
    {
        $company = array_merge([
            'companyId' => $companyId,
            'companyName' => 'Transportes del Sur',
            'locale' => 'ES',
            'phone' => '5493514449999',
            'enabled' => true,
            'hour' => '08:00',
            'thresholdDays' => 7,
            'whatsappInstanceId' => 'default',
        ], $overrides);

        $companies = [];
        foreach ($this->companies as $current) {
            if ((int) $current['companyId'] !== $companyId) {
                $companies[] = $current;
            }
        }
        $companies[] = $company;

        return new self($companies);
    }
}
