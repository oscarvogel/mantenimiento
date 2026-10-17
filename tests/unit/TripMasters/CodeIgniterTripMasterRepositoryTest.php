<?php

declare(strict_types=1);

namespace Tests\Unit\TripMasters;

use App\Infrastructure\TripMasters\CodeIgniterTripMasterRepository;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\MySQLi\Connection;
use CodeIgniter\Test\CIUnitTestCase;

/** Exercises real MySQL query compilation without opening a connection. */
final class CodeIgniterTripMasterRepositoryTest extends CIUnitTestCase
{
    private array $queries = [];
    private CodeIgniterTripMasterRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $result = $this->createMock(BaseResult::class);
        $result->method('getResultArray')->willReturn([['id' => 91, 'nombre' => 'Importado']]);
        $result->method('getNumRows')->willReturn(1);
        $db = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([['DBPrefix' => '', 'DBDebug' => true]])
            ->onlyMethods(['table', 'query', 'escapeString', 'insertID'])->getMock();
        $db->method('table')->willReturnCallback(static fn ($table) => new \CodeIgniter\Database\MySQLi\Builder($table, $db));
        $db->method('escapeString')->willReturnCallback(static fn ($value) => addslashes($value));
        $db->method('insertID')->willReturn(91);
        $db->method('query')->willReturnCallback(function ($sql, $binds = [], $setEscapeFlags = true) use ($result) {
            $this->queries[] = ['sql' => $sql, 'binds' => $binds];
            return $result;
        });
        $this->repository = new CodeIgniterTripMasterRepository($db);
    }

    public function testListsCanonicalCustomersScopedByCompanyAndActiveStatus(): void
    {
        self::assertSame([['id' => 91, 'nombre' => 'Importado']], $this->repository->list('clientes', 7, false));
        self::assertStringContainsString('FROM `clientes`', $this->queries[0]['sql']);
        self::assertStringContainsString('`clientes`.`empresa_id` =', $this->queries[0]['sql']);
        self::assertStringContainsString('`clientes`.`activo` =', $this->queries[0]['sql']);
        self::assertStringContainsString('`clientes`.`deleted_at` IS NULL', $this->queries[0]['sql']);
        self::assertSame(7, $this->queries[0]['binds']['clientes.empresa_id'][0]);
    }

    public function testManualCreationsReceiveDifferentSourceReferencesAndCanonicalColumns(): void
    {
        self::assertSame(91, $this->repository->add('clientes', 7, ['nombre' => 'Uno'], 12));
        $this->repository->add('clientes', 7, ['nombre' => 'Dos'], 12);
        foreach ($this->queries as $query) {
            self::assertStringContainsString('INSERT INTO `clientes`', $query['sql']);
            self::assertStringNotContainsString('created_by', $query['sql']);
            self::assertStringNotContainsString('updated_by', $query['sql']);
            self::assertSame('MANUAL_VIAJES', $query['binds']['origen'][0]);
            self::assertSame(7, $query['binds']['empresa_id'][0]);
            self::assertNotEmpty($query['binds']['codigo_origen'][0]);
            self::assertLessThanOrEqual(80, strlen($query['binds']['codigo_origen'][0]));
        }
        self::assertNotSame($this->queries[0]['binds']['codigo_origen'][0], $this->queries[1]['binds']['codigo_origen'][0]);
    }

    public function testCustomerUpdateAndInactivationKeepSourceAndScope(): void
    {
        $this->repository->update('clientes', 7, 91, ['nombre' => 'Editado'], 12);
        $this->repository->setActive('clientes', 7, 91, false, 12);
        foreach ($this->queries as $query) {
            self::assertStringContainsString('UPDATE `clientes`', $query['sql']);
            self::assertStringNotContainsString('updated_by', $query['sql']);
            self::assertStringNotContainsString('codigo_origen', $query['sql']);
            self::assertStringNotContainsString('`origen`', $query['sql']);
            self::assertStringContainsString('`deleted_at` IS NULL', $query['sql']);
            self::assertSame(7, $query['binds']['empresa_id'][0]);
            self::assertSame(91, $query['binds']['id'][0]);
        }
        self::assertSame(0, $this->queries[1]['binds']['activo'][0]);
    }

    public function testDomicilesJoinCanonicalCustomerWithinSameCompany(): void
    {
        $this->repository->list('domicilios', 7);
        self::assertStringContainsString('FROM `viajes_domicilios_clientes`', $this->queries[0]['sql']);
        self::assertStringContainsString('JOIN `clientes` `c`', $this->queries[0]['sql']);
        self::assertStringContainsString('`c`.`empresa_id` = `viajes_domicilios_clientes`.`empresa_id`', $this->queries[0]['sql']);
        self::assertSame(7, $this->queries[0]['binds']['viajes_domicilios_clientes.empresa_id'][0]);
    }

    public function testDomicileWritesRetainViajesFieldsAndAuthor(): void
    {
        $this->repository->add('domicilios', 7, ['cliente_id' => 91, 'alias' => 'Planta'], 12);
        self::assertStringContainsString('INSERT INTO `viajes_domicilios_clientes`', $this->queries[0]['sql']);
        self::assertSame(91, $this->queries[0]['binds']['cliente_id'][0]);
        self::assertSame(12, $this->queries[0]['binds']['created_by'][0]);
    }

    public function testCustomerMembershipUsesCanonicalCompanyScopeAndExcludesDeleted(): void
    {
        $this->repository->belongsToCompany('clientes', 7, 91);
        self::assertStringContainsString('FROM `clientes`', $this->queries[0]['sql']);
        self::assertStringContainsString('`deleted_at` IS NULL', $this->queries[0]['sql']);
        self::assertSame(7, $this->queries[0]['binds']['empresa_id'][0]);
        self::assertSame(91, $this->queries[0]['binds']['id'][0]);
    }
}
