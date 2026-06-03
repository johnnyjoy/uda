<?php

declare(strict_types=1);

namespace Tests\Oracle;

use UDA\Exception\QueryException;
use UDA\Query\Select;
use UDA\Query\WhereBuilder;



final class PaginationTest extends OracleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetNumbersTable();
    }

    public function testLimitOnly(): void
    {
        $builder = $this->baseQuery()->limit(3);

        $sql = strtoupper($builder->toSql()->getQuery());
        $this->assertStringContainsString('FETCH FIRST 3 ROWS ONLY', $sql);
        $this->assertSame($sql, strtoupper($builder->toSql()->getQuery()));

        $rows = $this->fetchIds($builder);
        $this->assertSame([1, 2, 3], $rows);
    }

    public function testOffsetOnly(): void
    {
        $builder = $this->baseQuery()->offset(5);

        $sql = strtoupper($builder->toSql()->getQuery());
        $this->assertStringContainsString('OFFSET 5 ROWS', $sql);

        $rows = $this->fetchIds($builder);
        $this->assertSame([6, 7, 8, 9, 10], $rows);
    }

    public function testLimitWithOffset(): void
    {
        $builder = $this->baseQuery()->limit(3)->offset(4);

        $sql = strtoupper($builder->toSql()->getQuery());
        $this->assertStringContainsString('OFFSET 4 ROWS', $sql);
        $this->assertStringContainsString('FETCH NEXT 3 ROWS ONLY', $sql);

        $rows = $this->fetchIds($builder);
        $this->assertSame([5, 6, 7], $rows);
    }

    public function testDeterministicOrdering(): void
    {
        $builder = $this->db()->select()
            ->selectRaw('id AS "id"')
            ->from('UDA_TEST_NUMBERS')
            ->orderBy('VALUE', 'DESC')
            ->limit(3);

        $rows = $this->fetchIds($builder);
        $this->assertSame([10, 9, 8], $rows);
    }

    public function testPaginationWithWhere(): void
    {
        $builder = $this->db()->select()
            ->selectRaw('id AS "id"')
            ->from('UDA_TEST_NUMBERS');
        /** @var WhereBuilder $where */
        $where = $builder->where('VALUE', 30, '>');
        $builder = $where->end()
            ->orderBy('id')
            ->limit(3);

        $rows = $this->fetchIds($builder);
        $this->assertSame([4, 5, 6], $rows);
    }

    public function testPaginationWithBuilderParameters(): void
    {
        $builder = $this->db()->select()
            ->selectRaw('id AS "id"')
            ->from('UDA_TEST_NUMBERS');
        /** @var WhereBuilder $where */
        $where = $builder->where('VALUE', 20, '>');
        $builder = $where->end()
            ->orderBy('id')
            ->limit(2)
            ->offset(2);

        $rows = $this->fetchIds($builder);
        $this->assertSame([5, 6], $rows);
    }

    public function testStreamingPagination(): void
    {
        $builder = $this->baseQuery()->limit(5);
        $ids = [];
        $count = $builder->each(function (array $row) use (&$ids): void {
            $ids[] = $this->extractId($row);
        });

        $this->assertSame(5, $count);
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testInvalidLimitThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Limit must be zero or positive');

        $this->baseQuery()->limit(-1)->rows();
    }

    /**
     * @return Select
     */
    private function baseQuery(): Select
    {
        return $this->db()->select()
            ->selectRaw('id AS "id"')
            ->from('UDA_TEST_NUMBERS')
            ->orderBy('id');
    }

    /**
     * @param Select $builder
     * @return array<int,int>
     */
    private function fetchIds(Select $builder): array
    {
        $rows = $builder->rows();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->extractId($row);
        }

        return $ids;
    }

    private function extractId(array $row): int
    {
        if (isset($row['ID'])) {
            return (int) $row['ID'];
        }

        if (isset($row['id'])) {
            return (int) $row['id'];
        }

        return (int) (array_values($row)[0] ?? 0);
    }
}
