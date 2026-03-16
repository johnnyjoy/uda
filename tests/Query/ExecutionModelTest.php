<?php

declare(strict_types=1);

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use UDA\Database;
use UDA\Exception\QueryException;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;

final class ExecutionModelTest extends TestCase
{
    private Database $db;
    private string $configFile;

    protected function setUp(): void
    {
        \UDA\Config::clearForTests();

        $config = [
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'params' => ['path' => ':memory:'],
                ],
            ],
        ];

        $this->configFile = sys_get_temp_dir() . '/uda-query-tests-' . uniqid('', true) . '.json';
        file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));

        $this->db = Database::connect('main', $this->configFile);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, status TEXT)');

        $seed = $this->db->insert()
            ->into('users')
            ->set('name', 'alpha')
            ->set('status', 'active');
        $this->db->exec($seed->toSql());
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            unset($this->db);
        }
        \UDA\Config::clearForTests();

        if (isset($this->configFile) && file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    public function testSelectTerminatorsDelegateToDatabase(): void
    {
        $builder = $this->db->select()
            ->select('id', 'name')
            ->from('users');

        $rows = $builder->rows();
        $this->assertCount(1, $rows);

        $row = $builder->row();
        $this->assertNotNull($row);
        $this->assertSame('alpha', $row['name']);

        $iterations = 0;
        $builder->each(function (array $record) use (&$iterations): void {
            $iterations++;
        });
        $this->assertSame(1, $iterations);

        $singleColumn = $this->db->select()
            ->select('name')
            ->from('users');

        $this->assertSame('alpha', $singleColumn->value());
        $this->assertSame(['alpha'], $singleColumn->values());
        $this->assertSame(['alpha'], $singleColumn->list());
    }

    public function testInsertTerminatorsDelegateToDatabase(): void
    {
        $builder = $this->db->insert()
            ->into('users')
            ->set('name', 'gamma')
            ->set('status', 'active');

        $this->assertSame(1, $builder->exec());

        $all = $this->db->select()->select('name')->from('users')->rows();
        $this->assertCount(2, $all);
    }

    public function testStandaloneBuildersWithoutDatabaseCannotExecute(): void
    {
        $builder = (new Select())
            ->select('id')
            ->from('users');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Database');

        $builder->rows();
    }

    public function testDatabaseExecutesSqlGeneratedByBuilders(): void
    {
        $insert = $this->db->insert()
            ->into('users')
            ->set('name', 'bravo')
            ->set('status', 'active');
        $affected = $this->db->exec($insert->toSql());
        $this->assertSame(1, $affected);

        $select = $this->db->select()
            ->select('name', 'status')
            ->from('users');

        /** @var WhereBuilder $where */
        $where = $select->where('status', 'active');
        $select = $where->end();

        $rows = $this->db->rows($select->toSql());
        $names = array_column($rows, 'name');
        $this->assertContains('alpha', $names);
        $this->assertContains('bravo', $names);

        $update = $this->db->update()
            ->table('users')
            ->set('status', 'archived');

        /** @var WhereBuilder $updateWhere */
        $updateWhere = $update->where('name', 'alpha');
        $update = $updateWhere->end();

        $this->assertSame(1, $this->db->exec($update->toSql()));

        $verify = $this->db->select()
            ->select('status')
            ->from('users');
        /** @var WhereBuilder $verifyWhere */
        $verifyWhere = $verify->where('name', 'alpha');
        $verify = $verifyWhere->end();

        $row = $this->db->row($verify->toSql());
        $this->assertSame('archived', $row['status']);
    }

    public function testInsertReturningRowsViaDatabase(): void
    {
        $builder = $this->db->insert()
            ->into('users')
            ->set('name', 'charlie')
            ->set('status', 'pending')
            ->returning('id', 'name');

        $rows = $this->db->returning($builder->toSql());

        $this->assertSame('charlie', $rows[0]['name']);
        $this->assertArrayHasKey('id', $rows[0]);
    }

    public function testInsertReturningRowsViaBuilderTerminators(): void
    {
        $builder = $this->db->insert()
            ->into('users')
            ->set('name', 'delta')
            ->set('status', 'pending')
            ->returning('id');

        $row = $builder->row();
        $this->assertNotNull($row);

        $verify = $this->db->select()
            ->select('name')
            ->from('users');
        /** @var WhereBuilder $verifyWhere */
        $verifyWhere = $verify->where('id', $row['id']);
        $verify = $verifyWhere->end();

        $this->assertSame('delta', $this->db->row($verify->toSql())['name']);
    }
}
