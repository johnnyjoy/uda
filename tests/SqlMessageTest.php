<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\SqlMessage;

class SqlMessageTest extends TestCase
{
    public function testSqlObjectStoresQueryStringAndParams(): void
    {
        $sqlString = "SELECT * FROM users WHERE id = :p1";
        $params = ['p1' => 123];

        $sql = new SqlMessage($sqlString, $params);

        $this->assertEquals($sqlString, $sql->getQuery());
        $this->assertEquals($params, $sql->getParams());
    }

    public function testSqlObjectCanBeConvertedToString(): void
    {
        $sqlString = "SELECT * FROM users WHERE id = :p1";
        $params = ['p1' => 123];

        $sql = new SqlMessage($sqlString, $params);

        $this->assertEquals($sqlString, (string) $sql);
    }

    public function testSqlObjectsAreImmutable(): void
    {
        $sqlString = "SELECT * FROM users WHERE id = :p1";
        $params = ['p1' => 123];

        $sql = new SqlMessage($sqlString, $params);
        $originalQuery = $sql->getQuery();
        $originalParams = $sql->getParams();

        // Attempting to modify should not affect original object
        $newSql = $sql->withQuery("SELECT * FROM posts");

        $this->assertEquals($sqlString, $sql->getQuery());
        $this->assertEquals($originalQuery, $sql->getQuery());
        $this->assertEquals($originalParams, $sql->getParams());
        $this->assertEquals("SELECT * FROM posts", $newSql->getQuery());
        $this->assertEquals($params, $newSql->getParams());
    }
}
