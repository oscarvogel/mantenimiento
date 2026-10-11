<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\ConfigureTelemetryIntegration;
use App\Application\Telematic\ConfigureTelemetryIntegrationResult;
use App\Application\Telematic\Port\TelemetryIntegrationConfigurator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ConfigureTelemetryIntegrationTest extends TestCase
{
    public function testConfiguraProveedorYUsaNombrePredeterminado(): void
    {
        $port = new FakeTelemetryIntegrationConfigurator();
        $useCase = new ConfigureTelemetryIntegration($port);

        $result = $useCase->execute(12, 34, ' WIALON ', null, ' token-secreto ');

        self::assertSame(91, $result->integrationId);
        self::assertSame([12, 34, 'wialon', 'Wialon', 'token-secreto'], $port->arguments);
    }

    public function testRechazaProveedorNoImplementadoSinInvocarElPuerto(): void
    {
        $port = new FakeTelemetryIntegrationConfigurator();

        try {
            (new ConfigureTelemetryIntegration($port))->execute(12, 34, 'gestya', null, 'token');
            self::fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            self::assertSame('Ese proveedor todavía no está disponible para conectar.', $exception->getMessage());
        }

        self::assertSame([], $port->arguments);
    }

    public function testRechazaTokenVacio(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pegá un token válido de hasta 4096 caracteres.');

        (new ConfigureTelemetryIntegration(new FakeTelemetryIntegrationConfigurator()))
            ->execute(12, 34, 'wialon', null, '  ');
    }
}

final class FakeTelemetryIntegrationConfigurator implements TelemetryIntegrationConfigurator
{
    /** @var list<mixed> */
    public array $arguments = [];

    public function configure(int $companyId, int $userId, string $provider, string $name, string $token): ConfigureTelemetryIntegrationResult
    {
        $this->arguments = [$companyId, $userId, $provider, $name, $token];

        return new ConfigureTelemetryIntegrationResult(91, 2, []);
    }
}
