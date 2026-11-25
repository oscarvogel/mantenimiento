<?php

declare(strict_types=1);

namespace Tests\Support\Neumaticos;

use App\Database\Migrations\CreateNeumaticoColocaciones;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Instala, sobre una base SQLite de test, lo que las colocaciones necesitan:
 * una tabla `equipos` mínima (dueña: Registro de activos) con los tipos reales
 * de sus columnas, y la migración real de `neumatico_colocaciones`.
 *
 * `equipos.estado` es VARCHAR 'ACTIVO' como en la migración de equipos, no un
 * entero: un tipo divergente haría pasar en verde una consulta que en MySQL
 * no devuelve filas.
 */
final class NeumaticSchema
{
    public static function installMountings(BaseConnection $database): void
    {
        if (! $database->tableExists('tipos_equipo', false)) {
            $database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre VARCHAR(100) NOT NULL)');
        }
        if (! $database->tableExists('equipos', false)) {
            $database->query('CREATE TABLE equipos ('
                . 'id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, sucursal_id INTEGER NOT NULL, '
                . 'tipo_equipo_id INTEGER NOT NULL, codigo VARCHAR(50) NOT NULL, patente VARCHAR(20) NULL, '
                . "estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO', deleted_at DATETIME NULL, "
                . 'UNIQUE (empresa_id, id))');
        }

        require_once APPPATH . 'Database/Migrations/2026-11-25-150000_CreateNeumaticoColocaciones.php';
        (new CreateNeumaticoColocaciones(Database::forge($database)))->up();
    }
}
