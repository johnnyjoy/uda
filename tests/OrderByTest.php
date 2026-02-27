<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\OrderBy;
use UDA\SQL\InvalidOrderByException;

class OrderByTest extends TestCase
{
    public function testValidColumnPassesAllowlist(): void
    {
        $orderBy = OrderBy::allow('name', ['name', 'created_at']);

        $this->assertEquals('name ASC', $orderBy->getExpression());
    }

    public function testDescendingOrder(): void
    {
        $orderBy = OrderBy::allow('name', ['name', 'created_at'])->desc();

        $this->assertEquals('name DESC', $orderBy->getExpression());
    }

    public function testInvalidColumnThrowsException(): void
    {
        $this->expectException(InvalidOrderByException::class);

        OrderBy::allow('invalid_column', ['name', 'created_at']);
    }

    public function testMultipleColumns(): void
    {
        $orderBy1 = OrderBy::allow('name', ['name', 'created_at']);
        $orderBy2 = OrderBy::allow('created_at', ['name', 'created_at'])->desc();

        $this->assertEquals('name ASC', $orderBy1->getExpression());
        $this->assertEquals('created_at DESC', $orderBy2->getExpression());
    }

    public function testEmptyAllowlistThrowsException(): void
    {
        $this->expectException(InvalidOrderByException::class);

        OrderBy::allow('name', []);
    }
}