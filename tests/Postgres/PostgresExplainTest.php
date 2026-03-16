<?php

declare(strict_types=1);

namespace Tests\Postgres;

use UDA\Database;

final class PostgresExplainTest extends PostgresTestCase
{
    public function testExplainSelectReturnsPlanRows(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $plan = $db->select()
                ->select('id', 'name')
                ->from('employees')
                ->explain();

            self::assertNotEmpty($plan);
            self::assertArrayHasKey('QUERY PLAN', $plan[0]);
        });
    }

    public function testExplainAnalyzeReturnsPlanRows(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $plan = $db->select()
                ->select('id')
                ->from('departments')
                ->explainAnalyze();

            self::assertNotEmpty($plan);
            self::assertArrayHasKey('QUERY PLAN', $plan[0]);
        });
    }

    public function testExplainInsertHasNoSideEffects(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $baseline = (int) $db->value('SELECT COUNT(*) FROM transactions');

            $db->insert()
                ->into('transactions')
                ->set('account', 'explain-insert')
                ->set('amount', 101.25)
                ->explain();

            $after = (int) $db->value('SELECT COUNT(*) FROM transactions');
            self::assertSame($baseline, $after, 'EXPLAIN should not mutate data');
        });
    }

    public function testRawSqlExplain(): void
    {
        $this->withPostgresDb(function (Database $db): void {
            $plans = $db->explain('SELECT COUNT(*) FROM employees');

            self::assertNotEmpty($plans);
            self::assertArrayHasKey('QUERY PLAN', $plans[0]);
        });
    }
}
