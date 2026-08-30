<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterEquipmentWorkOrderEvidenceReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterEquipmentWorkOrderEvidenceReadModelTest extends TestCase
{
    private BaseConnection $database;
    private CodeIgniterEquipmentWorkOrderEvidenceReadModel $readModel;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La lectura de evidencias requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE ordenes_trabajo (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            sucursal_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            responsable_usuario_id INTEGER NULL
        )');
        $this->database->query('CREATE TABLE equipo_adjuntos (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            equipo_id INTEGER NOT NULL,
            orden_id INTEGER NULL,
            nombre_original TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            tamanio INTEGER NOT NULL,
            descripcion TEXT NULL,
            created_at TEXT NOT NULL,
            retirado_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE ot_document_imports (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            equipo_id INTEGER NULL,
            original_name TEXT NOT NULL,
            private_relative_path TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->database->query('CREATE TABLE ot_document_import_orders (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            import_id INTEGER NOT NULL,
            orden_id INTEGER NOT NULL
        )');

        $this->database->table('ordenes_trabajo')->insertBatch([
            ['id' => 101, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'responsable_usuario_id' => 22],
            ['id' => 102, 'empresa_id' => 5, 'sucursal_id' => 8, 'equipo_id' => 10, 'responsable_usuario_id' => 22],
            ['id' => 103, 'empresa_id' => 9, 'sucursal_id' => 7, 'equipo_id' => 10, 'responsable_usuario_id' => 22],
            ['id' => 104, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 11, 'responsable_usuario_id' => 22],
            ['id' => 105, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'responsable_usuario_id' => 99],
            ['id' => 106, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'responsable_usuario_id' => 22],
        ]);
        $this->database->table('equipo_adjuntos')->insertBatch([
            ['id' => 201, 'empresa_id' => 5, 'equipo_id' => 10, 'orden_id' => 101, 'nombre_original' => 'foto.jpg', 'mime_type' => 'image/jpeg', 'tamanio' => 100, 'descripcion' => 'Foto', 'created_at' => '2026-08-30 12:00:00', 'retirado_at' => null],
            ['id' => 202, 'empresa_id' => 5, 'equipo_id' => 10, 'orden_id' => 101, 'nombre_original' => 'retirado.pdf', 'mime_type' => 'application/pdf', 'tamanio' => 100, 'descripcion' => null, 'created_at' => '2026-08-30 11:00:00', 'retirado_at' => '2026-08-30 11:30:00'],
            ['id' => 203, 'empresa_id' => 5, 'equipo_id' => 10, 'orden_id' => 102, 'nombre_original' => 'otra.png', 'mime_type' => 'image/png', 'tamanio' => 100, 'descripcion' => null, 'created_at' => '2026-08-30 10:00:00', 'retirado_at' => null],
            ['id' => 204, 'empresa_id' => 9, 'equipo_id' => 10, 'orden_id' => 103, 'nombre_original' => 'otra-empresa.jpg', 'mime_type' => 'image/jpeg', 'tamanio' => 100, 'descripcion' => null, 'created_at' => '2026-08-30 09:00:00', 'retirado_at' => null],
            ['id' => 205, 'empresa_id' => 5, 'equipo_id' => 11, 'orden_id' => 104, 'nombre_original' => 'otro-equipo.jpg', 'mime_type' => 'image/jpeg', 'tamanio' => 100, 'descripcion' => null, 'created_at' => '2026-08-30 08:00:00', 'retirado_at' => null],
            ['id' => 206, 'empresa_id' => 5, 'equipo_id' => 10, 'orden_id' => 105, 'nombre_original' => 'responsable.jpg', 'mime_type' => 'image/jpeg', 'tamanio' => 100, 'descripcion' => null, 'created_at' => '2026-08-30 07:00:00', 'retirado_at' => null],
        ]);
        $this->database->table('ot_document_imports')->insertBatch([
            ['id' => 301, 'empresa_id' => 5, 'equipo_id' => 10, 'original_name' => 'orden-tony.jpg', 'private_relative_path' => '5/orden-tony.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 200, 'created_at' => '2026-08-30 13:00:00'],
            ['id' => 302, 'empresa_id' => 5, 'equipo_id' => 10, 'original_name' => 'preventivo.pdf', 'private_relative_path' => '5/preventivo.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 200, 'created_at' => '2026-08-30 06:00:00'],
            ['id' => 303, 'empresa_id' => 9, 'equipo_id' => 10, 'original_name' => 'privado.jpg', 'private_relative_path' => '9/privado.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 200, 'created_at' => '2026-08-30 05:00:00'],
        ]);
        $this->database->table('ot_document_import_orders')->insertBatch([
            ['id' => 401, 'empresa_id' => 5, 'import_id' => 301, 'orden_id' => 101],
            ['id' => 402, 'empresa_id' => 5, 'import_id' => 301, 'orden_id' => 102],
            ['id' => 403, 'empresa_id' => 9, 'import_id' => 303, 'orden_id' => 103],
            ['id' => 404, 'empresa_id' => 5, 'import_id' => 302, 'orden_id' => 105],
        ]);

        $this->readModel = new CodeIgniterEquipmentWorkOrderEvidenceReadModel($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testResolvesAttachmentAndImportedDocumentForEachAuthorizedOrder(): void
    {
        $result = $this->readModel->forOrders($this->actor(['ordenes.ver'], true), 10, [101, 102, 103, 104, 105, 106]);

        self::assertSame(['ot_document_import', 'equipment_attachment'], array_column($result[101], 'source'));
        self::assertSame(['orden-tony.jpg', 'foto.jpg'], array_column($result[101], 'nombre_original'));
        self::assertSame(['ot_document_import', 'equipment_attachment'], array_column($result[102], 'source'));
        self::assertArrayNotHasKey(103, $result, 'No debe cruzar empresa.');
        self::assertArrayNotHasKey(104, $result, 'No debe cruzar equipo.');
        self::assertArrayHasKey(105, $result, 'Un usuario con ordenes.ver ve la OT autorizada de la misma empresa.');
        self::assertArrayNotHasKey(106, $result, 'Una OT sin evidencia no debe inventar archivos.');
        self::assertSame('5/orden-tony.jpg', $result[101][0]['private_relative_path']);
    }

    public function testAppliesBranchAndMyWorkScopeToImportedDocumentsAndAttachments(): void
    {
        $result = $this->readModel->forOrders($this->actor(['ordenes.mi_trabajo'], false, [7], 22), 10, [101, 102, 105]);

        self::assertArrayHasKey(101, $result);
        self::assertArrayNotHasKey(102, $result, 'No debe mostrar otra sucursal.');
        self::assertArrayNotHasKey(105, $result, 'Mi trabajo no debe mostrar otra OT responsable.');
    }

    public function testFindImportedDocumentRequiresTheAuthorizedOrderEquipmentAndTenant(): void
    {
        $actor = $this->actor(['equipos.ver'], true);

        self::assertNotNull($this->readModel->findImportedDocumentForOrder($actor, 10, 101, 301));
        self::assertNull($this->readModel->findImportedDocumentForOrder($actor, 11, 101, 301));
        self::assertNull($this->readModel->findImportedDocumentForOrder($actor, 10, 103, 303));
        self::assertNull($this->readModel->findImportedDocumentForOrder($actor, 10, 101, 999));
    }

    private function actor(array $permissions, bool $allBranches, array $branches = [], int $userId = 22): ActorContext
    {
        return new ActorContext($userId, 5, false, $allBranches, ['Responsable'], $permissions, $branches);
    }
}
