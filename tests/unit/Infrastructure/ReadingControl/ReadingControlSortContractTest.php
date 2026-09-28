<?php

declare(strict_types=1);

use App\Application\ReadingControl\ListReadingControl;
use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReadingControl\ReadingControlClockFake;

/**
 * Regresión del HTTP 500 en producción.
 *
 * `BaseBuilder::orderBy()` tiene la firma
 * `orderBy(string $orderBy, string $direction = '', ?bool $escape = null)`.
 * Pasar `false` como segundo argumento rompía el tipo de `$direction` y
 * Provocaba:
 *
 *   TypeError: BaseBuilder::orderBy(): Argument #2 ($direction) must be of
 *   type string, false given
 *
 * Esta prueba invoca el método privado `applySort()` sobre un constructor de
 * consultas real y verifica el ORDER BY compilado. No necesita tablas ni
 * datos: `compileSelect()` no toca la base.
 */
final class ReadingControlSortContractTest extends TestCase
{
    private function compiledOrderBy(string $sort): string
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de ordenamiento requiere sqlite3.');
        }

        $database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug'  => true,
        ], false);

        $useCase = new ListReadingControl(
            $database,
            new ReadingControlClockFake(new DateTimeImmutable('2026-09-28 10:00:00')),
        );

        $builder = $database->table('equipos e')
            ->select(['e.id'], false)
            ->join('(SELECT le.equipo_id, le.fecha_lectura FROM lecturas_equipo le) lr', 'lr.equipo_id = e.id', 'left', false);

        $method = new ReflectionMethod(ListReadingControl::class, 'applySort');
        $method->setAccessible(true);
        /** @var BaseBuilder $sorted */
        $sorted = $method->invoke($useCase, $builder, $sort);

        $sql = $sorted->compileSelect();

        $position = strripos($sql, 'ORDER BY');
        self::assertIsInt($position, 'La consulta debe terminar en ORDER BY.');

        return substr($sql, $position);
    }

    public function testDefaultOrderDoesNotRaiseATypeErrorAndSortsOldestFirst(): void
    {
        $orderBy = $this->compiledOrderBy('age_asc');

        // Los equipos sin lectura primero, luego del más antiguo al más reciente.
        self::assertStringContainsString('(lr.fecha_lectura IS NULL) DESC', $orderBy);
        self::assertStringContainsString('lr.fecha_lectura ASC', $orderBy);
        self::assertStringContainsString('e.codigo ASC', $orderBy);
    }

    public function testDescendingOrderIsAccepted(): void
    {
        $orderBy = $this->compiledOrderBy('age_desc');

        self::assertStringContainsString('(lr.fecha_lectura IS NULL) ASC', $orderBy);
        self::assertStringContainsString('lr.fecha_lectura DESC', $orderBy);
    }

    public function testCodeOrderIsAccepted(): void
    {
        self::assertStringContainsString('e.codigo ASC', $this->compiledOrderBy('code'));
    }

    public function testOrderByNeverReceivesABooleanAsDirection(): void
    {
        // El segundo argumento de orderBy() es la DIRECCION y debe ser string.
        // Este es exactamente el error que derribo produccion.
        foreach (['age_asc', 'age_desc', 'code'] as $sort) {
            $orderBy = $this->compiledOrderBy($sort);
            self::assertStringNotContainsString('false', $orderBy, $sort);
        }
    }
}
