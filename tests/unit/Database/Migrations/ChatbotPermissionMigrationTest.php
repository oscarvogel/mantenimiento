<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Verifica que la migración que inserta el permiso `chatbot.usar` es
 * idempotente, lo asigna a los roles esperados y NO al rol Consulta.
 *
 * Requiere la DB de testing con tablas `permisos`, `roles`, `rol_permisos`.
 * En el entorno local (sin DB de testing) el test se skipea honestamente.
 * En `fasa_195` corre contra MySQL y deja evidencia.
 */
final class ChatbotPermissionMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::connect();

        if (! $this->db->tableExists('permisos') || ! $this->db->tableExists('roles')) {
            $this->markTestSkipped('Las tablas `permisos`/`roles` no existen en el entorno local; se corre contra la DB de testing en CI.');
        }

        $now = date('Y-m-d H:i:s');
        foreach (['Administrador', 'Responsable de mantenimiento', 'Tecnico u operador', 'Solicitante', 'Consulta'] as $nombre) {
            if ($this->db->table('roles')->where('nombre', $nombre)->countAllResults() === 0) {
                $this->db->table('roles')->insert([
                    'nombre' => $nombre,
                    'descripcion' => 'descripcion de ' . $nombre,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->tableExists('permisos') && $this->db->tableExists('rol_permisos')) {
            $permission = $this->db->table('permisos')->where('clave', 'chatbot.usar')->get()->getRowArray();
            if ($permission !== null) {
                $this->db->table('rol_permisos')->where('permiso_id', $permission['id'])->delete();
                $this->db->table('permisos')->where('id', $permission['id'])->delete();
            }
        }

        parent::tearDown();
    }

    private function loadMigration(): \App\Database\Migrations\HardenChatbotTablesAndAddPermission
    {
        $file = APPPATH . 'Database/Migrations/2026-08-21-090000_HardenChatbotTablesAndAddPermission.php';
        if (! class_exists(\App\Database\Migrations\HardenChatbotTablesAndAddPermission::class, false)) {
            require_once $file;
        }
        return new \App\Database\Migrations\HardenChatbotTablesAndAddPermission();
    }

    public function testMigrationIdempotentAndAssignsToExpectedRoles(): void
    {
        $migration = $this->loadMigration();
        $migration->up();

        $first = $this->db->table('permisos')->where('clave', 'chatbot.usar')->get()->getRowArray();
        $this->assertNotNull($first, 'El permiso chatbot.usar no fue creado.');

        $migration->up();
        $count = $this->db->table('permisos')->where('clave', 'chatbot.usar')->countAllResults();
        $this->assertSame(1, $count);

        $assigned = array_column(
            $this->db->table('rol_permisos rp')
                ->join('roles r', 'r.id = rp.rol_id')
                ->where('rp.permiso_id', $first['id'])
                ->select('r.nombre')->get()->getResultArray(),
            'nombre'
        );

        $this->assertContains('Administrador', $assigned);
        $this->assertContains('Responsable de mantenimiento', $assigned);
        $this->assertContains('Tecnico u operador', $assigned);
        $this->assertContains('Solicitante', $assigned);
        $this->assertNotContains('Consulta', $assigned);
    }
}
