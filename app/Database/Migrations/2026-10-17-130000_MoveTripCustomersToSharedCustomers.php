<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Run with application writes paused. MariaDB DDL commits implicitly: the temporary
 * ID columns deliberately survive failures so the next run can finish unambiguously.
 */
final class MoveTripCustomersToSharedCustomers extends Migration
{
    private const DOMICILES = 'viajes_domicilios_clientes';
    private const AUTHORS = 'viajes_clientes_legacy_autoria';

    public function up(): void
    {
        $this->requireSchema();
        $fk = $this->customerForeignKey();
        $legacyExists = $this->exists('viajes_clientes');
        $hasLegacyId = $this->hasColumn('cliente_id_legacy');
        if ($this->hasColumn('cliente_id_canonico')) {
            throw new RuntimeException('Hay un rollback incompleto; terminar down() antes de migrar.');
        }
        if (! $legacyExists) {
            if (($fk['table'] ?? null) !== $this->db->prefixTable('clientes')) {
                throw new RuntimeException('Falta el padrón legacy y la FK canónica: se requiere revisión manual.');
            }
            $this->assertNoRows('SELECT d.id FROM ' . $this->table(self::DOMICILES) . ' d LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.id=d.cliente_id WHERE c.id IS NULL', 'Hay domicilios sin cliente canónico.');
            if ($hasLegacyId) {
                $this->assertUpDomicileMapping();
                $this->dropColumn('cliente_id_legacy');
            }
            return;
        }
        $this->requireDirection($fk, 'viajes_clientes', 'clientes', $hasLegacyId);
        $this->assertNoOtherLegacyReferences();
        $oldId = $hasLegacyId ? 'd.cliente_id_legacy' : 'd.cliente_id';
        if ($hasLegacyId && ($fk['table'] ?? null) === $this->db->prefixTable('viajes_clientes')) {
            $this->assertNoRows('SELECT id FROM ' . $this->table(self::DOMICILES) . ' WHERE cliente_id_legacy IS NOT NULL AND cliente_id_legacy<>cliente_id', 'La copia de IDs legacy es incompatible con la FK original.');
            $oldId = 'COALESCE(d.cliente_id_legacy,d.cliente_id)';
        }
        $this->assertNoRows('SELECT d.id FROM ' . $this->table(self::DOMICILES) . ' d LEFT JOIN ' . $this->table('viajes_clientes') . ' l ON l.empresa_id=d.empresa_id AND l.id=' . $oldId . ' WHERE l.id IS NULL', 'Hay domicilios sin cliente legacy de la misma empresa.');

        // Widen only. down() also retains this width, because later edits may use it.
        $phone = $this->db->query('SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH AS width FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->db->prefixTable('clientes'), 'telefono'])->getRowArray();
        if (($phone['DATA_TYPE'] ?? null) !== 'varchar') {
            throw new RuntimeException('El campo teléfono canónico tiene un tipo inesperado.');
        }
        if ((int) ($phone['width'] ?? 0) < 60) {
            $this->execute('ALTER TABLE ' . $this->table('clientes') . ' MODIFY telefono VARCHAR(60) NULL');
        }
        $this->execute('INSERT INTO ' . $this->table('clientes') . ' (empresa_id,nombre,identificacion_fiscal,telefono,email,activo,created_at,updated_at,origen,codigo_origen)
            SELECT l.empresa_id,l.nombre,l.identificacion_fiscal,l.telefono,l.email,l.activo,l.created_at,l.updated_at,\'viajes_legacy\',CAST(l.id AS CHAR)
            FROM ' . $this->table('viajes_clientes') . ' l LEFT JOIN ' . $this->table('clientes') . ' c ON ' . $this->mappingJoin() . ' WHERE c.id IS NULL');
        $this->assertNoRows('SELECT l.id FROM ' . $this->table('viajes_clientes') . ' l LEFT JOIN ' . $this->table('clientes') . ' c ON ' . $this->mappingJoin() . ' WHERE c.id IS NULL', 'No se pudo copiar todo el padrón legacy.');
        $this->createAuthorMetadata();
        $this->assertNoRows('SELECT l.id FROM ' . $this->table('viajes_clientes') . ' l JOIN ' . $this->table('clientes') . ' c ON ' . $this->mappingJoin() . ' JOIN ' . $this->table(self::AUTHORS) . ' a ON a.legacy_id=l.id WHERE a.empresa_id<>l.empresa_id OR a.cliente_id<>c.id', 'La autoría legacy tiene un mapeo incompatible.');
        $this->execute('INSERT INTO ' . $this->table(self::AUTHORS) . ' (legacy_id,empresa_id,cliente_id,created_by,updated_by)
            SELECT l.id,l.empresa_id,c.id,l.created_by,l.updated_by FROM ' . $this->table('viajes_clientes') . ' l JOIN ' . $this->table('clientes') . ' c ON ' . $this->mappingJoin() . ' LEFT JOIN ' . $this->table(self::AUTHORS) . ' a ON a.legacy_id=l.id WHERE a.legacy_id IS NULL');

        if (! $hasLegacyId) {
            $this->execute('ALTER TABLE ' . $this->table(self::DOMICILES) . ' ADD cliente_id_legacy INT UNSIGNED NULL');
        }
        if (($fk['table'] ?? null) === $this->db->prefixTable('viajes_clientes')) {
            $this->execute('UPDATE ' . $this->table(self::DOMICILES) . ' SET cliente_id_legacy=cliente_id WHERE cliente_id_legacy IS NULL');
            $this->dropForeignKey($fk['name']);
            $fk = null;
        }
        if ($fk === null) {
            // Mapping uses the saved legacy ID, never an already remapped cliente_id.
            $this->execute('UPDATE ' . $this->table(self::DOMICILES) . ' d JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.origen=\'viajes_legacy\' AND c.codigo_origen=CAST(d.cliente_id_legacy AS CHAR) SET d.cliente_id=c.id');
            $this->addForeignKey('clientes', 'fk_viajes_domicilios_cliente_canonico');
        }
        $this->assertUpDomicileMapping();
        $this->assertNoRows('SELECT l.id FROM ' . $this->table('viajes_clientes') . ' l LEFT JOIN ' . $this->table(self::AUTHORS) . ' a ON a.empresa_id=l.empresa_id AND a.legacy_id=l.id WHERE a.legacy_id IS NULL', 'Falta conservar autoría legacy.');
        $this->dropTable('viajes_clientes');
        $this->dropColumn('cliente_id_legacy');
    }

    public function down(): void
    {
        $this->requireSchema();
        $fk = $this->customerForeignKey();
        if ($this->hasColumn('cliente_id_legacy')) {
            throw new RuntimeException('Hay una migración incompleta; terminar up() antes del rollback.');
        }
        $hasCanonicalId = $this->hasColumn('cliente_id_canonico');
        if (($fk['table'] ?? null) === $this->db->prefixTable('viajes_clientes') && $this->exists('viajes_clientes')) {
            if ($hasCanonicalId) {
                $this->assertDownDomicileMapping();
                $this->dropColumn('cliente_id_canonico');
            }
            if ($this->exists(self::AUTHORS)) {
                $this->dropTable(self::AUTHORS);
            }
            return;
        }
        $this->requireDirection($fk, 'clientes', 'viajes_clientes', $hasCanonicalId);
        // IDs must be canonical decimal unsigned integers and globally unique, as in the old PK.
        $this->assertNoRows('SELECT id FROM ' . $this->table('clientes') . ' WHERE origen=\'viajes_legacy\' AND (codigo_origen NOT REGEXP \'^[1-9][0-9]{0,9}$\' OR CAST(codigo_origen AS UNSIGNED)>4294967295)', 'Referencia legacy inválida para reconstruir IDs.');
        $this->assertNoRows('SELECT codigo_origen FROM ' . $this->table('clientes') . ' WHERE origen=\'viajes_legacy\' GROUP BY codigo_origen HAVING COUNT(*)>1', 'Hay IDs legacy ambiguos entre empresas.');
        $canonicalId = $hasCanonicalId ? 'd.cliente_id_canonico' : 'd.cliente_id';
        if ($hasCanonicalId && ($fk['table'] ?? null) === $this->db->prefixTable('clientes')) {
            $this->assertNoRows('SELECT id FROM ' . $this->table(self::DOMICILES) . ' WHERE cliente_id_canonico IS NOT NULL AND cliente_id_canonico<>cliente_id', 'La copia de IDs canónicos es incompatible con la FK original.');
            $canonicalId = 'COALESCE(d.cliente_id_canonico,d.cliente_id)';
        }
        $this->assertNoRows('SELECT d.id FROM ' . $this->table(self::DOMICILES) . ' d LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.id=' . $canonicalId . ' AND c.origen=\'viajes_legacy\' WHERE c.id IS NULL', 'Rollback bloqueado: hay domicilios sin referencia legacy; conservar el esquema canónico o resolverlos explícitamente.');
        if (! $this->exists(self::AUTHORS)) {
            throw new RuntimeException('Falta la autoría legacy necesaria para un rollback sin pérdida.');
        }
        $this->assertNoRows('SELECT c.id FROM ' . $this->table('clientes') . ' c LEFT JOIN ' . $this->table(self::AUTHORS) . ' a ON a.empresa_id=c.empresa_id AND a.cliente_id=c.id AND a.legacy_id=CAST(c.codigo_origen AS UNSIGNED) WHERE c.origen=\'viajes_legacy\' AND a.legacy_id IS NULL', 'Falta autoría o equivalencia de un cliente legacy.');
        $this->createLegacyCatalog();
        $this->assertNoRows('SELECT l.id FROM ' . $this->table('viajes_clientes') . ' l LEFT JOIN ' . $this->table('clientes') . ' c ON ' . $this->mappingJoin() . ' WHERE c.id IS NULL', 'La tabla legacy parcial contiene IDs incompatibles.');
        // Match widened canonical sizes so edits made after up() are not truncated on rollback.
        $this->execute('INSERT INTO ' . $this->table('viajes_clientes') . ' (id,empresa_id,nombre,identificacion_fiscal,telefono,email,activo,created_at,updated_at,created_by,updated_by)
            SELECT CAST(c.codigo_origen AS UNSIGNED),c.empresa_id,c.nombre,c.identificacion_fiscal,c.telefono,c.email,c.activo,c.created_at,c.updated_at,a.created_by,a.updated_by
            FROM ' . $this->table('clientes') . ' c LEFT JOIN ' . $this->table(self::AUTHORS) . ' a ON a.empresa_id=c.empresa_id AND a.cliente_id=c.id WHERE c.origen=\'viajes_legacy\'
            ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),identificacion_fiscal=VALUES(identificacion_fiscal),telefono=VALUES(telefono),email=VALUES(email),activo=VALUES(activo),created_at=VALUES(created_at),updated_at=VALUES(updated_at),created_by=VALUES(created_by),updated_by=VALUES(updated_by)');
        $this->assertNoRows('SELECT c.id FROM ' . $this->table('clientes') . ' c LEFT JOIN ' . $this->table('viajes_clientes') . ' l ON ' . $this->mappingJoin() . ' WHERE c.origen=\'viajes_legacy\' AND l.id IS NULL', 'No se pudo reconstruir todo el padrón legacy.');
        if (! $hasCanonicalId) {
            $this->execute('ALTER TABLE ' . $this->table(self::DOMICILES) . ' ADD cliente_id_canonico INT UNSIGNED NULL');
        }
        if (($fk['table'] ?? null) === $this->db->prefixTable('clientes')) {
            $this->execute('UPDATE ' . $this->table(self::DOMICILES) . ' SET cliente_id_canonico=cliente_id WHERE cliente_id_canonico IS NULL');
            $this->dropForeignKey($fk['name']);
        }
        $this->execute('UPDATE ' . $this->table(self::DOMICILES) . ' d JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.id=d.cliente_id_canonico AND c.origen=\'viajes_legacy\' SET d.cliente_id=CAST(c.codigo_origen AS UNSIGNED)');
        $this->addForeignKey('viajes_clientes', 'viajes_domicilios_clientes_empresa_id_cliente_id_foreign');
        $this->assertDownDomicileMapping();
        $this->dropColumn('cliente_id_canonico');
        $this->dropTable(self::AUTHORS);
        // Canonical customers and their import history deliberately survive down().
    }

    private function requireSchema(): void
    {
        if ($this->db->DBDriver !== 'MySQLi' || ! $this->exists('clientes') || ! $this->exists(self::DOMICILES)) {
            throw new RuntimeException('La migración requiere MySQL/MariaDB con clientes y domicilios existentes.');
        }
    }

    /** @return array{name:string,table:string}|null */
    private function customerForeignKey(): ?array
    {
        $rows = $this->db->query('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION', [$this->db->prefixTable(self::DOMICILES)])->getResultArray();
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['CONSTRAINT_NAME']][] = $row;
        }
        $found = null;
        foreach ($groups as $name => $columns) {
            if (! in_array('cliente_id', array_column($columns, 'COLUMN_NAME'), true)) {
                continue;
            }
            if ($found !== null || array_column($columns, 'COLUMN_NAME') !== ['empresa_id', 'cliente_id'] || array_column($columns, 'REFERENCED_COLUMN_NAME') !== ['empresa_id', 'id']) {
                throw new RuntimeException('La FK de clientes tiene una estructura inesperada.');
            }
            $found = ['name' => $name, 'table' => $columns[0]['REFERENCED_TABLE_NAME']];
        }
        return $found;
    }

    private function requireDirection(?array $fk, string $source, string $destination, bool $hasSavedId): void
    {
        if (($fk['table'] ?? null) === $this->db->prefixTable($source)) {
            return;
        }
        if ($hasSavedId && ($fk === null || $fk['table'] === $this->db->prefixTable($destination))) {
            return;
        }
        throw new RuntimeException('Estado parcial inesperado de la FK de clientes; no se modificaron datos.');
    }

    private function assertNoOtherLegacyReferences(): void
    {
        $rows = $this->db->query('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=? AND TABLE_NAME<>? LIMIT 1', [$this->db->prefixTable('viajes_clientes'), $this->db->prefixTable(self::DOMICILES)])->getResultArray();
        if ($rows !== []) {
            throw new RuntimeException('Existen otras referencias al padrón legacy; migrarlas antes de retirar la tabla.');
        }
    }

    private function createAuthorMetadata(): void
    {
        if ($this->exists(self::AUTHORS)) {
            return;
        }
        $this->execute('CREATE TABLE ' . $this->table(self::AUTHORS) . ' (
            legacy_id INT UNSIGNED NOT NULL PRIMARY KEY, empresa_id INT UNSIGNED NOT NULL, cliente_id INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
            UNIQUE KEY uq_legacy_autoria_empresa (empresa_id,legacy_id), UNIQUE KEY uq_legacy_autoria_cliente (empresa_id,cliente_id),
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_autoria_cliente')) . ' FOREIGN KEY (empresa_id,cliente_id) REFERENCES ' . $this->table('clientes') . '(empresa_id,id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_autoria_creador')) . ' FOREIGN KEY (created_by) REFERENCES ' . $this->table('usuarios') . '(id) ON UPDATE SET NULL ON DELETE RESTRICT,
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_autoria_editor')) . ' FOREIGN KEY (updated_by) REFERENCES ' . $this->table('usuarios') . '(id) ON UPDATE SET NULL ON DELETE RESTRICT
        ) ENGINE=InnoDB');
    }

    private function createLegacyCatalog(): void
    {
        if ($this->exists('viajes_clientes')) {
            return;
        }
        $this->execute('CREATE TABLE ' . $this->table('viajes_clientes') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(255) NOT NULL, identificacion_fiscal VARCHAR(50) NULL, telefono VARCHAR(60) NULL, email VARCHAR(255) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL,
            created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
            UNIQUE KEY uq_viajes_clientes_empresa_id (empresa_id,id), KEY idx_legacy_activo (empresa_id,activo), KEY idx_legacy_nombre (empresa_id,nombre),
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_empresa')) . ' FOREIGN KEY (empresa_id) REFERENCES ' . $this->table('empresas') . '(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_creador')) . ' FOREIGN KEY (created_by) REFERENCES ' . $this->table('usuarios') . '(id) ON UPDATE SET NULL ON DELETE RESTRICT,
            CONSTRAINT ' . $this->identifier($this->constraintName('fk_viajes_legacy_editor')) . ' FOREIGN KEY (updated_by) REFERENCES ' . $this->table('usuarios') . '(id) ON UPDATE SET NULL ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function mappingJoin(): string
    {
        return "c.empresa_id=l.empresa_id AND c.origen='viajes_legacy' AND c.codigo_origen=CAST(l.id AS CHAR)";
    }

    private function assertUpDomicileMapping(): void
    {
        $this->assertNoRows('SELECT d.id FROM ' . $this->table(self::DOMICILES) . ' d LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.id=d.cliente_id AND c.origen=\'viajes_legacy\' AND c.codigo_origen=CAST(d.cliente_id_legacy AS CHAR) WHERE c.id IS NULL', 'El remapeo de domicilios no coincide con sus referencias legacy.');
    }

    private function assertDownDomicileMapping(): void
    {
        $this->assertNoRows('SELECT d.id FROM ' . $this->table(self::DOMICILES) . ' d LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id=d.empresa_id AND c.id=d.cliente_id_canonico AND c.origen=\'viajes_legacy\' AND c.codigo_origen=CAST(d.cliente_id AS CHAR) WHERE c.id IS NULL', 'No coinciden los domicilios restaurados con el cliente canónico.');
    }

    private function assertNoRows(string $sql, string $message): void
    {
        if ($this->db->query($sql . ' LIMIT 1')->getRowArray() !== null) {
            throw new RuntimeException($message);
        }
    }

    private function exists(string $table): bool
    {
        return $this->db->tableExists($table, false);
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->db->prefixTable(self::DOMICILES), $column])->getRowArray() !== null;
    }

    private function dropColumn(string $column): void
    {
        $this->execute('ALTER TABLE ' . $this->table(self::DOMICILES) . ' DROP COLUMN ' . $this->identifier($column));
    }

    private function dropForeignKey(string $name): void
    {
        $this->execute('ALTER TABLE ' . $this->table(self::DOMICILES) . ' DROP FOREIGN KEY ' . $this->identifier($name));
    }

    private function addForeignKey(string $table, string $name): void
    {
        $this->execute('ALTER TABLE ' . $this->table(self::DOMICILES) . ' ADD CONSTRAINT ' . $this->identifier($this->constraintName($name)) . ' FOREIGN KEY (empresa_id,cliente_id) REFERENCES ' . $this->table($table) . '(empresa_id,id) ON UPDATE CASCADE ON DELETE RESTRICT');
    }

    private function constraintName(string $name): string
    {
        // InnoDB constraint names are unique across a schema, including prefixed fixtures.
        return $this->db->DBPrefix === '' ? $name : substr($name, 0, 45) . '_' . substr(sha1($this->db->DBPrefix), 0, 12);
    }

    private function execute(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('No se pudo completar una operación de la migración de clientes; revisar el log privado antes de reintentar.');
        }
    }

    private function dropTable(string $table): void
    {
        if (! $this->forge->dropTable($table)) {
            throw new RuntimeException('No se pudo retirar una tabla de la migración de clientes.');
        }
    }

    private function table(string $table): string
    {
        return $this->identifier($this->db->prefixTable($table));
    }

    private function identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
