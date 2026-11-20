<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backup;

use App\Infrastructure\Backup\MysqlDump;
use PHPUnit\Framework\TestCase;

final class MysqlDumpCompletenessTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAcceptsBackupThatEndsWithTheClosingLine(): void
    {
        $file = $this->gzip("SET NAMES utf8mb4;\nINSERT INTO `a` VALUES (1);\n" . MysqlDump::FOOTER_PREFIX . " 1 tablas, 0 vistas, 1 filas.\n\n");

        self::assertTrue(MysqlDump::isComplete($file));
    }

    public function testRejectsTruncatedBackup(): void
    {
        $file = $this->gzip("SET NAMES utf8mb4;\nINSERT INTO `a` VALUES (1);\n");

        self::assertFalse(MysqlDump::isComplete($file));
    }

    public function testRejectsMissingFile(): void
    {
        self::assertFalse(MysqlDump::isComplete(sys_get_temp_dir() . '/respaldo-inexistente.sql.gz'));
    }

    private function gzip(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'respaldo') . '.sql.gz';
        $this->files[] = $file;
        file_put_contents($file, gzencode($content));

        return $file;
    }
}
