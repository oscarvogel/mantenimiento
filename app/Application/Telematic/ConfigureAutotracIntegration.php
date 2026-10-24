<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\AutotracIntegrationConfigurator;
use DomainException;

final readonly class ConfigureAutotracIntegration
{
    public function __construct(private AutotracIntegrationConfigurator $configurator)
    {
    }

    public function execute(
        int $companyId,
        int $userId,
        ?string $name,
        string $subscriptionKey,
        string $username,
        string $password,
        string $accountCode,
    ): ConfigureTelemetryIntegrationResult {
        if ($companyId <= 0 || $userId <= 0) {
            throw new DomainException('No se pudo identificar la empresa para guardar la integración.');
        }

        $credentials = TestAutotracConnection::credentials($subscriptionKey, $username, $password);
        $accountCode = trim($accountCode);
        if (! ctype_digit($accountCode) || (int) $accountCode <= 0) {
            throw new DomainException('Probá la conexión y seleccioná una cuenta Autotrac válida.');
        }

        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Autotrac ' . $accountCode;
        }
        if (mb_strlen($name) > 100) {
            throw new DomainException('El nombre de la cuenta no puede superar 100 caracteres.');
        }

        return $this->configurator->configure(
            $companyId,
            $userId,
            $name,
            $credentials + ['accountCode' => (int) $accountCode],
        );
    }
}
