<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Una carga de combustible puede registrarse sin precio: se anotan los litros
 * en el surtidor y el importe se completa después, cuando llega el precio.
 * Mientras importe sea NULL la carga cuenta para consumo pero no para costos.
 */
final class AllowOperationalFuelWithoutPrice extends Migration
{
    private const COLUMNS = ['importe', 'importe_ars'];

    public function up(): void
    {
        $fields = [];
        foreach (self::COLUMNS as $column) {
            $fields[$column] = ['name' => $column, 'type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true];
        }

        $this->forge->modifyColumn('viaje_operativo_combustible', $fields);
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('viaje_operativo_combustible', true);
        $pending = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE importe IS NULL OR importe_ars IS NULL',
        )->getRowArray()['total'];
        if ($pending > 0) {
            throw new RuntimeException('down() no puede volver obligatorio el importe mientras haya cargas de combustible sin precio.');
        }

        $fields = [];
        foreach (self::COLUMNS as $column) {
            $fields[$column] = ['name' => $column, 'type' => 'DECIMAL', 'constraint' => '18,4', 'null' => false];
        }

        $this->forge->modifyColumn('viaje_operativo_combustible', $fields);
    }
}
