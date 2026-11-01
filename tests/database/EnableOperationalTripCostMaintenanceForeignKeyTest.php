<?php

declare(strict_types=1);

use App\Database\Migrations\EnableOperationalTripCostMaintenance;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use PHPUnit\Framework\TestCase;

final class EnableOperationalTripCostMaintenanceForeignKeyTest extends TestCase
{
    public function testAddedAuditColumnForeignKeyReferencesUsersId(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-11-01-100000_EnableOperationalTripCostMaintenance.php';

        $connection = $this->createMock(BaseConnection::class);
        $connection->method('getForeignKeyData')->willReturn([]);

        $forge = new EnableOperationalTripCostMaintenanceForeignKeyForge($connection);
        $migration = new EnableOperationalTripCostMaintenance($forge);

        $method = new ReflectionMethod(EnableOperationalTripCostMaintenance::class, 'addForeignKeyIfMissing');
        $method->invoke($migration, 'viaje_operativo_combustible', 'updated_by');

        $foreignKeys = new ReflectionProperty(Forge::class, 'foreignKeys');
        self::assertSame([
            [
                'field' => ['updated_by'],
                'referenceTable' => 'usuarios',
                'referenceField' => ['id'],
                'onDelete' => 'SET NULL',
                'onUpdate' => 'RESTRICT',
                'fkName' => 'fk_viaje_operativo_combustible_updated_by',
            ],
        ], $foreignKeys->getValue($forge));
    }
}

final class EnableOperationalTripCostMaintenanceForeignKeyForge extends Forge
{
    public function __construct(BaseConnection $connection)
    {
        $this->db = $connection;
    }

    public function processIndexes(string $table): bool
    {
        return true;
    }
}
