<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * Carga opciones ficticias para revisar los catálogos de Viajes en staging.
 *
 * Ejecución deliberada: TRIP_MASTERS_DEMO_TARGET=TSA php spark db:seed TripMastersDemoSeeder
 */
final class TripMastersDemoSeeder extends Seeder
{
    private const DEMO_RATE_START = '2099-01-01';
    private const DEMO_RATES = [10.00, 12.50, 15.00, 17.50, 20.00];

    public function run(): void
    {
        if (ENVIRONMENT !== 'development') {
            throw new RuntimeException('Los datos demo de maestros solo se pueden generar en desarrollo/staging.');
        }

        if (strtoupper(trim((string) getenv('TRIP_MASTERS_DEMO_TARGET'))) !== 'TSA') {
            throw new RuntimeException('Para confirmar el destino, ejecute con TRIP_MASTERS_DEMO_TARGET=TSA.');
        }

        $companyId = $this->findTsaCompanyId();
        $this->targetCompanyId = $companyId;
        $this->db->transBegin();

        try {
            $counts = [
                'tipos de carga' => $this->seedCatalog('viajes_tipos_carga', self::loadTypes()),
                'unidades de carga' => $this->seedCatalog('viajes_unidades_carga', self::loadUnits()),
                'categorías de gastos' => $this->seedCatalog('viajes_categorias_gasto', self::expenseCategories()),
                'medios de pago' => $this->seedCatalog('viajes_medios_pago', self::paymentMethods()),
                'tipos de documento' => $this->seedCatalog('viajes_tipos_documento', self::documentTypes()),
                'porcentajes de liquidación' => $this->seedDemoRates($companyId),
            ];

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('La base rechazó la generación de los datos demo de Viajes.');
            }

            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        CLI::write('Datos ficticios para TSA:');
        foreach ($counts as $label => $count) {
            CLI::write(sprintf('  %s: %d nuevos', $label, $count));
        }
        CLI::write('Las liquidaciones demo se guardan inactivas y con vigencia 2099 para que no se apliquen a operaciones.', 'yellow');
    }

    private function findTsaCompanyId(): int
    {
        $companies = $this->db->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $matches = array_values(array_filter($companies, static function (array $company): bool {
            return strtoupper(trim((string) ($company['nombre_fantasia'] ?? ''))) === 'TSA'
                || strtoupper(trim((string) ($company['razon_social'] ?? ''))) === 'TSA';
        }));

        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Se esperaba una empresa activa llamada TSA y se encontraron %d. No se insertaron datos.',
                count($matches)
            ));
        }

        return (int) $matches[0]['id'];
    }

    /** @param list<array<string,string|null>> $rows */
    private function seedCatalog(string $table, array $rows): int
    {
        $inserted = 0;
        foreach ($rows as $row) {
            $existing = $this->db->table($table)
                ->where('empresa_id', $this->companyId())
                ->where('nombre', $row['nombre'])
                ->get()
                ->getRowArray();

            if ($existing !== null) {
                continue;
            }

            $this->db->table($table)->insert($row + [
                'empresa_id' => $this->companyId(),
                'activo' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $inserted++;
        }

        return $inserted;
    }

    private ?int $targetCompanyId = null;

    private function companyId(): int
    {
        if ($this->targetCompanyId === null) {
            throw new RuntimeException('Primero se debe validar la empresa TSA antes de cargar catálogos.');
        }

        return $this->targetCompanyId;
    }

    private function seedDemoRates(int $companyId): int
    {
        $employees = $this->db->table('empleados')
            ->select('id')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $inserted = 0;
        foreach ($employees as $index => $employee) {
            $employeeId = (int) $employee['id'];
            $existing = $this->db->table('viajes_porcentajes_liquidacion_chofer')
                ->where('empresa_id', $companyId)
                ->where('empleado_id', $employeeId)
                ->where('vigente_desde', self::DEMO_RATE_START)
                ->get()
                ->getRowArray();

            if ($existing !== null) {
                continue;
            }

            $this->db->table('viajes_porcentajes_liquidacion_chofer')->insert([
                'empresa_id' => $companyId,
                'empleado_id' => $employeeId,
                'porcentaje' => self::DEMO_RATES[$index % count(self::DEMO_RATES)],
                'vigente_desde' => self::DEMO_RATE_START,
                'vigente_hasta' => self::DEMO_RATE_START,
                'activo' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /** @return list<array{nombre:string,descripcion:string}> */
    private static function loadTypes(): array
    {
        return [
            ['nombre' => 'Carga general', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga refrigerada', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga a granel', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga contenerizada', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga peligrosa', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga sobredimensionada', 'descripcion' => 'Dato ficticio de demostración.'],
        ];
    }

    /** @return list<array{nombre:string,simbolo:string}> */
    private static function loadUnits(): array
    {
        return [
            ['nombre' => 'Pallet (demo)', 'simbolo' => 'PLT'],
            ['nombre' => 'Bulto (demo)', 'simbolo' => 'BUL'],
            ['nombre' => 'Tonelada (demo)', 'simbolo' => 't'],
            ['nombre' => 'Kilogramo (demo)', 'simbolo' => 'kg'],
            ['nombre' => 'Metro cúbico (demo)', 'simbolo' => 'm³'],
        ];
    }

    /** @return list<array{nombre:string,descripcion:string}> */
    private static function expenseCategories(): array
    {
        return [
            ['nombre' => 'Combustible', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Peajes', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Viáticos', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Alojamiento', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Carga y descarga', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Estacionamiento', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Mantenimiento', 'descripcion' => 'Dato ficticio de demostración.'],
        ];
    }

    /** @return list<array{nombre:string,descripcion:string}> */
    private static function paymentMethods(): array
    {
        return [
            ['nombre' => 'Efectivo', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Transferencia bancaria', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Tarjeta de débito', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Tarjeta de crédito', 'descripcion' => 'Dato ficticio de demostración.'],
        ];
    }

    /** @return list<array{nombre:string,codigo:string,descripcion:string}> */
    private static function documentTypes(): array
    {
        return [
            ['nombre' => 'Factura', 'codigo' => 'FACTURA', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Ticket', 'codigo' => 'TICKET', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Remito', 'codigo' => 'REMITO', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Recibo', 'codigo' => 'RECIBO', 'descripcion' => 'Dato ficticio de demostración.'],
            ['nombre' => 'Otro comprobante', 'codigo' => 'OTRO', 'descripcion' => 'Dato ficticio de demostración.'],
        ];
    }
}
