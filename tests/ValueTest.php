<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\Value;
use UDA\SQL\ParamBag;

class ValueTest extends TestCase
{
    public function testValueParameterizesScalarValues(): void
    {
        $bag = new ParamBag();

        $result = Value::param($bag, 'test');

        $this->assertEquals(':p1', $result);
        $this->assertEquals(['p1' => 'test'], $bag->getParams());
    }

    public function testValueParameterizesIntegers(): void
    {
        $bag = new ParamBag();

        $result = Value::param($bag, 42);

        $this->assertEquals(':p1', $result);
        $this->assertEquals(['p1' => 42], $bag->getParams());
    }

    public function testValueParameterizesNull(): void
    {
        $bag = new ParamBag();

        $result = Value::param($bag, null);

        $this->assertEquals(':p1', $result);
        $this->assertEquals(['p1' => null], $bag->getParams());
    }

    public function testValueParameterizesBooleans(): void
    {
        $bag = new ParamBag();

        $result1 = Value::param($bag, true);
        $result2 = Value::param($bag, false);

        $this->assertEquals(':p1', $result1);
        $this->assertEquals(':p2', $result2);
        $this->assertEquals(['p1' => true, 'p2' => false], $bag->getParams());
    }

    public function testValueHandlesMultipleCalls(): void
    {
        $bag = new ParamBag();

        Value::param($bag, 'first');
        Value::param($bag, 'second');
        Value::param($bag, 'third');

        $params = $bag->getParams();
        $this->assertEquals(3, count($params));
        $this->assertEquals(['p1' => 'first', 'p2' => 'second', 'p3' => 'third'], $params);
    }
}