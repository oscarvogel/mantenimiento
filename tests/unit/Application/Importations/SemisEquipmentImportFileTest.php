<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Importations\ImportRowValidator;
use App\Application\Importations\Port\ImportReferenceGateway;
use App\Application\Importations\SpreadsheetData;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use App\Infrastructure\Assets\CodeIgniterEquipmentFleetLookup;
use App\Infrastructure\Importations\NativeCsvSpreadsheetReader;
use PHPUnit\Framework\TestCase;

/**
 * Carga de los semirremolques de TSA (documento `docs/SEMI.docx`).
 *
 * Los dos CSV de `docs/carga_semis/` son la forma de cargar las 27 unidades
 * por el módulo de Importaciones (tipo EQUIPOS). Estos tests fijan lo que el
 * importador realmente exige y lo que el usuario ve en pantalla:
 *
 *  1. Los encabezados son los que declara `ImportType::EQUIPOS`: si el
 *     contrato de la importación cambia, el archivo hay que regenerarlo
 *     (`php scripts/generar-csv-semis.php`) en vez de subir uno viejo.
 *  2. Las 27 filas pasan la validación del importador contra el catálogo real
 *     (tipo `Semirremolque`, sucursales TSAARG y TSABR, marcas Vulcano,
 *     Randon y Facchini). Si falta un catálogo, la fila sale con error en la
 *     previsualización y no se carga.
 *  3. El tipo `Semirremolque` se clasifica como semirremolque y no como
 *     tractor según la regla de producción, que es la que decide qué lista
 *     alimenta el combo "Acoplado o semirremolque" de la pantalla de viaje.
 *  4. El inventario coincide con el documento: 14 unidades de TSA Argentina y
 *     13 de TSA Brasil, con su patente, año, chasis y marca.
 *
 * La regla de clasificación vive hoy sobre el nombre del tipo de equipo
 * (`tipos_equipo.nombre`), duplicada en dos adaptadores. Por eso el test
 * también verifica que las dos copias digan lo mismo: si divergen, una unidad
 * puede verse en la flota y no aparecer en el formulario de viaje.
 */
final class SemisEquipmentImportFileTest extends TestCase
{
    /**
     * Archivo => cantidad de filas de datos.
     *
     * @var array<string, int>
     */
    private const FILES = [
        'docs/carga_semis/semis_argentina.csv' => 14,
        'docs/carga_semis/semis_brasil.csv' => 13,
    ];

    /**
     * Patente => [sucursal, anio, chasis, marca].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const INVENTORY = [
        'AH137XF' => ['TSAARG', '2025', '8C9ST1553S0VU9014', 'Vulcano'],
        'ONN137' => ['TSAARG', '2015', '8B8GSRA3FFM006431', 'Vulcano'],
        'AB264AY' => ['TSAARG', '2017', '8B8GSRA3FHM007442', 'Vulcano'],
        'IAF639' => ['TSAARG', '2009', '8B8GSRA3F9M002577', 'Vulcano'],
        'NAR553' => ['TSAARG', '2013', '8B8GSRA3FDM005266', 'Vulcano'],
        'AF336NP' => ['TSAARG', '2022', '8B8GSRA3CNM010193', 'Vulcano'],
        'AE766KU' => ['TSAARG', '2021', '8B8GSRA3CMM009206', 'Vulcano'],
        'AA833CX' => ['TSAARG', '2017', '8B8GSRA3FHM007327', 'Vulcano'],
        'AF854XE' => ['TSAARG', '2023', '8B8GSRA3CPM011040', 'Vulcano'],
        'AD982GM' => ['TSAARG', '2019', '8B8GSRA3FLM008759', 'Vulcano'],
        'AE928DO' => ['TSAARG', '2021', '8B8GSRA3CMM009272', 'Vulcano'],
        'AF081OH' => ['TSAARG', '2021', '8B8GSRA3CNM009767', 'Vulcano'],
        'AE611RN' => ['TSAARG', '2021', '8B8CSRA3CM009053', 'Vulcano'],
        'AF902UB' => ['TSAARG', '2023', '8B8GSRA3CPM010997', 'Vulcano'],
        'IUW1F97' => ['TSABR', '2014', '955L1513DES358431', 'Randon'],
        'JCE2I90' => ['TSABR', '2023', '94BF1543PPV004916', 'Facchini'],
        'RHB4B13' => ['TSABR', '2021', '9ADG1453GGM401290', 'Facchini'],
        'IYQ3E75' => ['TSABR', '2018', '94BF1483JJV057789', 'Randon'],
        'JCJ0H66' => ['TSABR', '2023', '94BF1543PRV008216', 'Facchini'],
        'JCN8I76' => ['TSABR', '2024', '94BF1543RRV001461', 'Facchini'],
        'LZV0A24' => ['TSABR', '2014', '9A9FR389ECDW8864', 'Randon'],
        'JAP4D98' => ['TSABR', '2021', '9ADF1473MMC013143', 'Randon'],
        'BEN2E47' => ['TSABR', '2021', '9ADF1473LMC012181', 'Randon'],
        'BEN4E07' => ['TSABR', '2021', '9ADF1473LMC012182', 'Randon'],
        'RHB1F55' => ['TSABR', '2022', '9ADF1473MNC015744', 'Randon'],
        'BEN5E43' => ['TSABR', '2021', '9ADF1473LMC012333', 'Randon'],
        'RHB1B77' => ['TSABR', '2022', '9ADF1473MNC014630', 'Randon'],
    ];

    public function testLosEncabezadosSonLosQueEsperaElImportadorDeEquipos(): void
    {
        $expected = ImportType::EQUIPOS->templateHeaders();

        foreach (self::FILES as $file => $rows) {
            $data = $this->read($file);

            self::assertSame($expected, $data->headers, 'Encabezados de ' . $file);
            self::assertCount($rows, $data->rows, 'Filas de datos de ' . $file);
        }
    }

    public function testCadaFilaPasaLaValidacionDelImportador(): void
    {
        $validator = new ImportRowValidator(new SemisCatalogGatewayFake());
        $actor = $this->actor([7, 8]);

        foreach (self::FILES as $file => $expectedRows) {
            $data = $this->read($file);
            $validator->beginFile();
            $number = 0;

            foreach ($data->rows as $row) {
                $number++;
                $result = $validator->validate(ImportType::EQUIPOS, $row, $number, $actor, 5);
                $errors = array_filter(
                    $result->issues,
                    static fn ($issue): bool => $issue->severity === 'ERROR'
                );

                self::assertSame(
                    [],
                    array_map(static fn ($issue): string => $issue->field . ': ' . $issue->message, array_values($errors)),
                    'Fila ' . $number . ' de ' . $file
                );
                self::assertSame(
                    ImportRowStatus::VALIDA,
                    $result->status,
                    'Estado de la fila ' . $number . ' de ' . $file
                );
            }

            self::assertCount($expectedRows, $data->rows);
        }
    }

    public function testLasUnidadesSeClasificanComoSemirremolqueYNoComoTractor(): void
    {
        $lookup = (new ReflectionClass(CodeIgniterEquipmentFleetLookup::class))->newInstanceWithoutConstructor();
        $isTrailer = new ReflectionMethod(CodeIgniterEquipmentFleetLookup::class, 'isTrailer');
        $isTractor = new ReflectionMethod(CodeIgniterEquipmentFleetLookup::class, 'isTractor');

        foreach (self::FILES as $file => $rows) {
            foreach ($this->read($file)->rows as $row) {
                $type = (string) $row['tipo_equipo'];

                self::assertTrue(
                    $isTrailer->invoke($lookup, $type),
                    $type . ' debe clasificarse como semirremolque (' . $file . ')'
                );
                self::assertFalse(
                    $isTractor->invoke($lookup, $type),
                    $type . ' no debe clasificarse como tractor (' . $file . ')'
                );
            }
        }
    }

    public function testLaReglaDeClasificacionEsLaMismaEnLaFlotaYEnElFormularioDeViaje(): void
    {
        $root = dirname(__DIR__, 4);
        $fleet = (string) file_get_contents($root . '/app/Infrastructure/Assets/CodeIgniterEquipmentFleetLookup.php');
        $trips = (string) file_get_contents($root . '/app/Infrastructure/Trips/CodeIgniterTripFormReadModel.php');
        $pattern = '/acoplado|semirremolque|remolque|batea/';

        self::assertStringContainsString($pattern, $fleet);
        self::assertStringContainsString($pattern, $trips);
        self::assertStringContainsString(
            '/camion|tractor|chasis|cabina|utilitario|pickup/',
            $fleet
        );
        self::assertStringContainsString(
            '/camion|tractor|chasis|cabina|utilitario|pickup/',
            $trips
        );
    }

    public function testElInventarioCoincideConElDocumento(): void
    {
        $seen = [];

        foreach (self::FILES as $file => $rows) {
            foreach ($this->read($file)->rows as $row) {
                $plate = (string) $row['patente'];
                self::assertArrayHasKey($plate, self::INVENTORY, 'Patente inesperada: ' . $plate);
                [$branch, $year, $chassis, $brand] = self::INVENTORY[$plate];

                self::assertSame($branch, $row['sucursal_codigo'], 'Sucursal de ' . $plate);
                self::assertSame($year, $row['anio'], 'Año de ' . $plate);
                self::assertSame($chassis, $row['chasis'], 'Chasis de ' . $plate);
                self::assertSame($brand, $row['marca'], 'Marca de ' . $plate);
                self::assertSame($plate, $row['codigo'], 'Código de ' . $plate);

                $seen[$plate] = true;
            }
        }

        self::assertCount(count(self::INVENTORY), $seen);
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::INVENTORY), array_keys($seen))),
            'Hay unidades del documento que no están en los CSV'
        );
    }

    public function testNoHayCodigosNiPatentesRepetidos(): void
    {
        $codes = [];
        $plates = [];

        foreach (array_keys(self::FILES) as $file) {
            foreach ($this->read($file)->rows as $row) {
                $code = (string) $row['codigo'];
                $plate = (string) $row['patente'];

                self::assertArrayNotHasKey($code, $codes, 'Código repetido: ' . $code);
                self::assertArrayNotHasKey($plate, $plates, 'Patente repetida: ' . $plate);
                $codes[$code] = true;
                $plates[$plate] = true;
            }
        }

        self::assertCount(count(self::INVENTORY), $codes);
    }

    public function testElChasisConEspaciosQuedaMarcadoComoPendienteDeConfirmacion(): void
    {
        // AE611RN viene en el documento como "8B8C SRA 3CM 009053". La carga
        // usa el texto del documento sin espacios y deja la pregunta escrita
        // en la ficha, en vez de completar letras que el documento no dice.
        $rows = [];
        foreach ($this->read('docs/carga_semis/semis_argentina.csv')->rows as $row) {
            $rows[(string) $row['patente']] = $row;
        }

        self::assertSame('8B8CSRA3CM009053', $rows['AE611RN']['chasis']);
        self::assertStringContainsString(
            'Pendiente de confirmar con el cliente',
            (string) $rows['AE611RN']['observaciones']
        );
        self::assertStringContainsString(
            '8B8C SRA 3CM 009053',
            (string) $rows['AE611RN']['observaciones']
        );
    }

    public function testLasMarcasInferidasQuedanMarcadasComoPendientes(): void
    {
        // La marca Vulcano sólo está escrita en el documento para AH137XF. En
        // las otras 13 viene inferida del WMI del chasis (riesgo R2 del plan)
        // y la ficha lo dice, para que no quede cargada en silencio.
        $rows = [];
        foreach ($this->read('docs/carga_semis/semis_argentina.csv')->rows as $row) {
            $rows[(string) $row['patente']] = $row;
        }

        self::assertStringContainsString(
            'informada en el documento',
            (string) $rows['AH137XF']['observaciones']
        );
        self::assertStringContainsString(
            'inferida del WMI del chasis',
            (string) $rows['AF902UB']['observaciones']
        );
        self::assertStringContainsString(
            'Confirmar con el cliente',
            (string) $rows['AF902UB']['observaciones']
        );
    }

    public function testSinAccesoATsaBrasilElArchivoBrasileroNoSeCarga(): void
    {
        // Riesgo R1 del plan de carga: un usuario habilitado solo para TSA
        // Argentina no ve las 13 carretas de TSA Brasil. El importador lo
        // rechaza por fila en vez de cargarlas en una sucursal ajena.
        $validator = new ImportRowValidator(new SemisCatalogGatewayFake());
        $actor = $this->actor([7]);

        $errors = 0;
        foreach ($this->read('docs/carga_semis/semis_brasil.csv')->rows as $index => $row) {
            $result = $validator->validate(ImportType::EQUIPOS, $row, $index + 1, $actor, 5);
            $errors += count(array_filter(
                $result->issues,
                static fn ($issue): bool => $issue->severity === 'ERROR'
            ));
        }

        self::assertSame(13, $errors);

        $validator->beginFile();
        $argentinaErrors = 0;
        foreach ($this->read('docs/carga_semis/semis_argentina.csv')->rows as $index => $row) {
            $result = $validator->validate(ImportType::EQUIPOS, $row, $index + 1, $actor, 5);
            $argentinaErrors += count(array_filter(
                $result->issues,
                static fn ($issue): bool => $issue->severity === 'ERROR'
            ));
        }

        self::assertSame(0, $argentinaErrors);
    }

    private function read(string $relativePath): SpreadsheetData
    {
        $path = dirname(__DIR__, 4) . '/' . $relativePath;
        self::assertFileExists($path, 'Falta el archivo de carga: ' . $relativePath);

        return (new NativeCsvSpreadsheetReader())->read($path);
    }

    /** @param list<int> $branches */
    private function actor(array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], ['importaciones.cargar'], $branches);
    }
}

/**
 * Catálogo mínimo que el importador necesita para dar por válida una fila:
 * el tipo de equipo, las dos sucursales y las tres marcas de la flota.
 */
final class SemisCatalogGatewayFake implements ImportReferenceGateway
{
    /** @var array<string, int> */
    private const BRANCHES = ['TSAARG' => 7, 'TSABR' => 8];

    /** @var array<string, int> */
    private const BRANDS = ['Vulcano' => 1, 'Randon' => 2, 'Facchini' => 3];

    public function activeBranchByCode(int $companyId, string $code): ?array
    {
        $id = self::BRANCHES[mb_strtoupper(trim($code))] ?? null;

        return $id === null ? null : ['id' => $id, 'codigo' => mb_strtoupper(trim($code))];
    }

    public function activeEquipmentTypeByName(string $name): ?array
    {
        if (mb_strtolower(trim($name)) !== 'semirremolque') {
            return null;
        }

        return ['id' => 9, 'nombre' => 'Semirremolque', 'controla_km' => true, 'controla_horas' => false];
    }

    public function activeBrandByName(int $companyId, string $name): ?array
    {
        foreach (self::BRANDS as $brand => $id) {
            if (mb_strtolower($brand) === mb_strtolower(trim($name))) {
                return ['id' => $id, 'nombre' => $brand];
            }
        }

        return null;
    }

    public function activeModelByName(int $companyId, int $brandId, int $typeId, string $name): ?array
    {
        return null;
    }

    public function activeEquipmentByCode(int $companyId, string $code): ?array
    {
        return null;
    }

    public function activeEquipmentByCodeOrPlate(int $companyId, string $value): ?array
    {
        return null;
    }

    public function activeEmployeeByName(int $companyId, string $name): ?array
    {
        return null;
    }

    public function equipmentCodeExists(int $companyId, string $code): bool
    {
        return false;
    }

    public function equipmentPlateExists(int $companyId, string $plate): bool
    {
        return false;
    }

    public function readingDuplicateExists(
        int $companyId,
        int $equipmentId,
        string $recordedAt,
        ?int $kilometers,
        ?string $hours,
        string $origin
    ): bool {
        return false;
    }
}
