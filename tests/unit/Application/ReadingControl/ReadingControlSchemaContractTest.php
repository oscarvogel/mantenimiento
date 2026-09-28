<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadingControl\PhpSource;

/**
 * Auditoría de esquema del control de lecturas.
 *
 * Deriva las columnas realmente declaradas por las migraciones del repositorio
 * y verifica, una por una, que cada columna usada por la consulta exista.
 *
 * Este es el control que habría detenido el hotfix anterior: la consulta
 * original usaba `tipos_equipo.deleted_at` y las columnas `created_by` /
 * `deleted_at` de la tabla de entregas de WhatsApp, y ninguna de las tres
 * existe en el esquema real. En MariaDB eso produce ERROR 1054 y un HTTP 500.
 */
final class ReadingControlSchemaContractTest extends TestCase
{
    /**
     * Columnas que la consulta de control de lectures necesita, por tabla.
     *
     * @return array<string, list<string>>
     */
    private static function expectedSchema(): array
    {
        return [
            'equipos' => ['id', 'empresa_id', 'sucursal_id', 'tipo_equipo_id', 'codigo', 'patente', 'chasis', 'estado', 'deleted_at'],
            'tipos_equipo' => ['id', 'nombre', 'controla_km', 'activo'],
            'sucursales' => ['id', 'empresa_id', 'nombre', 'estado', 'deleted_at'],
            'employee_equipment_assignments' => ['id', 'empresa_id', 'equipo_id', 'empleado_id', 'rol', 'fecha_desde', 'fecha_hasta'],
            'empleados' => ['id', 'empresa_id', 'nombre', 'apellido', 'telefono', 'activo', 'deleted_at'],
            'lecturas_equipo' => ['id', 'empresa_id', 'equipo_id', 'fecha_lectura', 'kilometraje', 'anulada'],
        ];
    }

    /**
     * Columnas que el esquema NO define. Su uso debe permanecer prohibido.
     *
     * @return array<string, list<string>>
     */
    private static function forbiddenColumns(): array
    {
        return [
            // `tipos_equipo` se da de baja con `activo`; nunca tuvo `deleted_at`.
            'tipos_equipo' => ['deleted_at'],
            // La tabla de lecturas no tiene borrado lógico ni auditoría de autor.
            'lecturas_equipo' => ['deleted_at', 'created_by'],
            'sucursales' => ['controla_km'],
            'empleados' => ['controla_km'],
        ];
    }

    public function testEveryColumnUsedByTheQueryExistsInTheSchema(): void
    {
        foreach (self::expectedSchema() as $table => $columns) {
            $declared = self::declaredColumns($table);

            self::assertNotEmpty($declared, sprintf('No se pudo derivar el esquema de "%s".', $table));

            foreach ($columns as $column) {
                self::assertContains(
                    $column,
                    $declared,
                    sprintf('La consulta usa "%s"."%s" pero ninguna migración la declara.', $table, $column),
                );
            }
        }
    }

    public function testTheQueryDoesNotUseColumnsMissingFromTheSchema(): void
    {
        foreach (self::forbiddenColumns() as $table => $columns) {
            $declared = self::declaredColumns($table);

            foreach ($columns as $column) {
                self::assertNotContains(
                    $column,
                    $declared,
                    sprintf('"%s"."%s" no existe en el esquema; no debe usarse.', $table, $column),
                );
            }
        }
    }

    public function testTheQueryDoesNotReferenceTheWhatsAppDeliveriesTable(): void
    {
        $code = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ListReadingControl.php');

        self::assertStringNotContainsString('notificacion_whatsapp_entregas', $code);
    }

    public function testLastReadingIsResolvedDeterministically(): void
    {
        // Se lee el código sin comentarios: el docblock explica por qué no se
        // usa MAX(fecha_lectura), y mentionarla ahí no es una dependencia.
        $code = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ListReadingControl.php');

        // Regresión: `MAX(fecha_lectura) ... GROUP BY` puede devolver el
        // kilometraje de otra fila. Se exige un desreferenciado con orden
        // explícito y desempate por id.
        self::assertStringNotContainsString('MAX(fecha_lectura)', $code);
        self::assertStringContainsString('ORDER BY l2.fecha_lectura DESC, l2.id DESC', $code);
        self::assertStringContainsString('LIMIT 1', $code);
        self::assertStringContainsString('anulada = 0', $code);
    }

    public function testAntiquityFilterIsAppliedInSqlNotAfterPagination(): void
    {
        $code = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ListReadingControl.php');

        // El total se cuenta sobre el conjunto filtrado completo, antes de
        // aplicar el límite de página.
        self::assertStringContainsString('countAllResults()', $code);
        self::assertStringContainsString('applyAntiquityFilter', $code);
        self::assertStringNotContainsString('filterMatches(', $code, 'El filtrado no debe resignarse a memoria después de paginar.');
    }

    /**
     * Deriva de las migraciones las columnas declaradas para una tabla.
     *
     * @return list<string>
     */
    private static function declaredColumns(string $table): array
    {
        $columns = [];

        foreach (glob(APPPATH . 'Database/Migrations/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Forma `addColumn('tabla', [ 'columna' => [...], ... ])`.
            $pattern = "/addColumn\(\s*'" . preg_quote($table, '/') . "'\s*,\s*\[(.*?)\]\s*\)/s";
            if (preg_match_all($pattern, $source, $matches) > 0) {
                foreach ($matches[1] as $block) {
                    $columns += self::arrayKeys($block);
                }
            }

            // Forma `createTable('tabla')` precedida por `addField([...])`.
            if (preg_match("/createTable\(\s*'" . preg_quote($table, '/') . "'/s", $source, $match, PREG_OFFSET_CAPTURE) === 1) {
                $createAt = $match[0][1];
                $fieldAt = strrpos(substr($source, 0, $createAt), 'addField([');

                if ($fieldAt !== false) {
                    $block = substr($source, $fieldAt);
                    $end = strpos($block, ']);');
                    $columns += self::arrayKeys($end === false ? $block : substr($block, 0, $end));
                }
            }
        }

        return array_keys($columns);
    }

    /**
     * @return array<string, true>
     */
    private static function arrayKeys(string $block): array
    {
        preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/", $block, $matches);

        return array_fill_keys($matches[1], true);
    }
}
