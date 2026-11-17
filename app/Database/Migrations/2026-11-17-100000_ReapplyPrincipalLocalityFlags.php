<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Infrastructure\Geography\PrincipalLocalityMarker;
use CodeIgniter\Database\Migration;

/**
 * Migración correctiva de la marca `principal` de las localidades (#146).
 *
 * Contexto: la migración `2026-11-06-100000_AddPrincipalFlagToLocalities`
 * podía abortar una instalación desde cero cuando el catálogo de Brasil
 * todavía no tenía provincias cargadas (`provincia_id IN ()` no es SQL
 * válido en MySQL/MariaDB). La implementación de la regla se corrigió y pasó
 * a un único lugar (`PrincipalLocalityMarker`).
 *
 * Qué hace esta migración:
 *
 * 1. Garantiza que la columna `principal` exista. En las instalaciones que ya
 *    aplicaron la migración original es un no-op.
 * 2. Vuelve a marcar las localidades que la regla declara principales **sin
 *    resetear** el resto de las marcas. Así:
 *    - una instalación que quedó a medio camino (columna creada, marcas de
 *      Uruguay y Chile aplicadas, las de Brasil nunca) queda reparada;
 *    - las marcas que puso el importador del catálogo
 *      (`PrincipalLocalityPolicy`) y las entradas de provincia de Argentina
 *      se conservan: esta migración no las borra ni las desmarca.
 *
 * Es idempotente: repetirla no cambia el resultado. En staging y producción
 * la medición previa dio 0 filas a agregar, es decir que hoy es un no-op.
 *
 * Su `down()` no destruye nada: la columna y las marcas pertenecen a la
 * migración original, así que revertir esta migración no las elimina.
 */
final class ReapplyPrincipalLocalityFlags extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('principal', 'localidades')) {
            $this->forge->addColumn('localidades', [
                'principal' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            ]);
            $this->db->resetDataCache();
        }

        (new PrincipalLocalityMarker($this->db))->markPrincipal();
    }

    public function down(): void
    {
        // La columna `principal` y las marcas de las localidades no pertenecen a
        // esta migración: las crea `2026-11-06-100000`. Revertirla no puede
        // borrarlas sin perder información que ya usan los viajes cargados.
        // Por eso el `down()` es vacío a propósito y sólo deja registro.
        log_message('notice', 'ReapplyPrincipalLocalityFlags revertida sin cambios: la columna `principal` y las marcas pertenecen a la migración 2026-11-06-100000.');
    }
}
