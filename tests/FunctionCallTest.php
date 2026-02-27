<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\FunctionCall;
use UDA\SQL\InvalidFunctionException;

class FunctionCallTest extends TestCase
{
    public function testValidFunctionPassesValidation(): void
    {
        $function = new FunctionCall('COUNT', '*');

        $this->assertEquals('COUNT(*)', $function->getExpression());
    }

    public function testFunctionWithMultipleArguments(): void
    {
        $function = new FunctionCall('CONCAT', 'first_name', 'last_name');

        $this->assertEquals('CONCAT(first_name, last_name)', $function->getExpression());
    }

    public function testInvalidFunctionThrowsException(): void
    {
        $this->expectException(InvalidFunctionException::class);

        new FunctionCall('INVALID_FUNCTION', 'arg');
    }

    public function testDangerousFunctionThrowsException(): void
    {
        $this->expectException(InvalidFunctionException::class);

        new FunctionCall('EXEC', 'some_code');
    }

    public function testFunctionCanBeConvertedToString(): void
    {
        $function = new FunctionCall('UPPER', 'name');

        $this->assertEquals('UPPER(name)', (string) $function);
    }
}