<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración histórica duplicada.
 *
 * Los campos demo de `empresas` se crean en
 * 2026-08-20-140000_AddDemoFieldsToEmpresas.php. Esta migración se mantiene
 * únicamente para conservar compatibilidad con historiales de migración ya
 * existentes, pero no debe volver a alterar el esquema.
 */
final class AddDemoFieldsToEmpresasV2 extends Migration
{
    public function up(): void
    {
        // Intencionalmente vacío: la migración 140000 es la única fuente
        // efectiva para es_demo y demo_expira_at.
    }

    public function down(): void
    {
        // Intencionalmente vacío. No debemos eliminar columnas que pertenecen
        // a la migración canónica 140000 al hacer rollback de esta versión.
    }
}
