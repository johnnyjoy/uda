<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\TableReference;
use UDA\SQL\InvalidIdentifierException;

class TableReferenceTest extends TestCase
{
    public function testTableReferenceCreatesValidReference(): void
    {
        $table = new TableReference('users');

        $this->assertEquals('users', $table->getName());
        $this->assertEquals('"users"', $table->quoted('sqlite'));
    }

    public function testTableReferenceWithSchema(): void
    {
        $table = new TableReference('public', 'users');

        $this->assertEquals('public.users', $table->getName());
        $this->assertEquals('"public"."users"', $table->quoted('pgsql'));
    }

    public function testInvalidTableReferenceThrowsException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new TableReference('users; DROP TABLE users;');
    }

    public function testEmptyTableNameThrowsException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new TableReference('');
    }

    public function testTableReferenceCanBeConvertedToString(): void
    {
        $table = new TableReference('users');

        $this->assertEquals('users', (string) $table);
    }
}