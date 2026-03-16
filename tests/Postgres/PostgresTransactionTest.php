<?php

declare(strict_types=1);

namespace Tests\Postgres;

use RuntimeException;
use UDA\Database;

final class PostgresTransactionTest extends PostgresTestCase
{
    public function testCommitPersistsInserts(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $baseline = (int) $db->value('SELECT COUNT(*) FROM transactions');

            $db->transaction(function () use ($db): void {
                $db->exec(
                    'INSERT INTO transactions (id, account, amount, created_at) VALUES (:id, :account, :amount, NOW())',
                    ['id' => 99001, 'account' => 'cert', 'amount' => 4321.10]
                );
            });

            $after = (int) $db->value('SELECT COUNT(*) FROM transactions');
            self::assertSame($baseline + 1, $after, 'Committed transaction did not persist row');

            $persisted = $db->row('SELECT account, amount FROM transactions WHERE id = :id', ['id' => 99001]);
            self::assertNotNull($persisted);
            self::assertSame('cert', $persisted['account']);

            $db->exec('DELETE FROM transactions WHERE id = :id', ['id' => 99001]);
        });
    }

    public function testRollbacksOnException(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $baseline = (int) $db->value('SELECT COUNT(*) FROM transactions');

            try {
                $db->transaction(function () use ($db): void {
                    $db->exec(
                        'INSERT INTO transactions (id, account, amount, created_at) VALUES (:id, :account, :amount, NOW())',
                        ['id' => 99002, 'account' => 'rollback', 'amount' => 1.23]
                    );

                    throw new RuntimeException('force rollback');
                });
                self::fail('Expected RuntimeException not thrown');
            } catch (RuntimeException $exception) {
                self::assertSame('force rollback', $exception->getMessage());
            }

            $after = (int) $db->value('SELECT COUNT(*) FROM transactions');
            self::assertSame($baseline, $after, 'Row should not persist after rollback');
            self::assertNull($db->row('SELECT id FROM transactions WHERE id = :id', ['id' => 99002]));
        });
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $db->exec('DELETE FROM transactions WHERE id BETWEEN 99010 AND 99020');

            $db->transaction(function () use ($db): void {
                $db->exec('INSERT INTO transactions (id, account, amount, created_at) VALUES (99011, :acct, :amt, NOW())', [
                    'acct' => 'outer-first',
                    'amt' => 10,
                ]);

                try {
                    $db->transaction(function () use ($db): void {
                        $db->exec('INSERT INTO transactions (id, account, amount, created_at) VALUES (99012, :acct, :amt, NOW())', [
                            'acct' => 'inner-fail',
                            'amt' => 20,
                        ]);

                        throw new RuntimeException('inner failure');
                    });
                    self::fail('Inner transaction should throw');
                } catch (RuntimeException $e) {
                    self::assertSame('inner failure', $e->getMessage());
                }

                $db->exec('INSERT INTO transactions (id, account, amount, created_at) VALUES (99013, :acct, :amt, NOW())', [
                    'acct' => 'outer-second',
                    'amt' => 30,
                ]);
            });

            $first = $db->row('SELECT account FROM transactions WHERE id = 99011');
            $inner = $db->row('SELECT account FROM transactions WHERE id = 99012');
            $second = $db->row('SELECT account FROM transactions WHERE id = 99013');

            self::assertNotNull($first, 'Outer insert should persist');
            self::assertSame('outer-first', $first['account']);
            self::assertNull($inner, 'Inner insert should roll back to savepoint');
            self::assertNotNull($second, 'Outer transaction should still persist after inner rollback');

            $db->exec('DELETE FROM transactions WHERE id IN (99011, 99013)');
        });
    }
}
