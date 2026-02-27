<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\Identifier;
use UDA\SQL\InvalidIdentifierException;

class IdentifierTest extends TestCase
{
    public function testValidIdentifierPassesValidation(): void
    {
        $identifier = new Identifier('users');

        $this->assertEquals('users', $identifier->getName());
        $this->assertEquals('"users"', $identifier->quoted('sqlite'));
        $this->assertEquals('`users`', $identifier->quoted('mysql'));
        $this->assertEquals('"users"', $identifier->quoted('pgsql'));
    }

    public function testMultipleSegmentsAreJoinedCorrectly(): void
    {
        $identifier = new Identifier('schema', 'table');

        $this->assertEquals('schema.table', $identifier->getName());
        $this->assertEquals('"schema"."table"', $identifier->quoted('pgsql'));
    }

    public function testInvalidCharactersThrowException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Identifier('user;drop table');
    }

    public function testEmptySegmentThrowsException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Identifier('');
    }

    public function testDangerousKeywordsThrowException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Identifier('DROP');
    }

    public function testSqlInjectionAttemptsThrowException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Identifier('users" WHERE 1=1 --');
    }
}