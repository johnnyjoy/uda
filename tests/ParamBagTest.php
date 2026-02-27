<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\ParamBag;

class ParamBagTest extends TestCase
{
    public function testParamBagAllocatesUniqueParameterNames(): void
    {
        $bag = new ParamBag();

        $param1 = $bag->alloc();
        $param2 = $bag->alloc();

        $this->assertNotEquals($param1, $param2);
        $this->assertStringStartsWith('p', $param1);
        $this->assertStringStartsWith('p', $param2);
    }

    public function testParamBagMaintainsMonotonicCounter(): void
    {
        $bag = new ParamBag();

        $param1 = $bag->alloc();
        $param2 = $bag->alloc();
        $param3 = $bag->alloc();

        // Should be p1, p2, p3, etc.
        $this->assertEquals('p1', $param1);
        $this->assertEquals('p2', $param2);
        $this->assertEquals('p3', $param3);
    }

    public function testParamBagCanAllocateWithCustomPrefix(): void
    {
        $bag = new ParamBag('custom');

        $param1 = $bag->alloc();
        $param2 = $bag->alloc();

        $this->assertEquals('custom1', $param1);
        $this->assertEquals('custom2', $param2);
    }

    public function testParamBagReturnsParametersInOrder(): void
    {
        $bag = new ParamBag();

        $bag->alloc();
        $bag->alloc();
        $params = $bag->getParams();

        $this->assertCount(0, $params); // No values assigned yet
    }

    public function testParamBagAssociatesValuesWithParameters(): void
    {
        $bag = new ParamBag();

        $param1 = $bag->alloc();
        $bag->assign($param1, 'value1');

        $param2 = $bag->alloc();
        $bag->assign($param2, 'value2');

        $params = $bag->getParams();

        $this->assertCount(2, $params);
        $this->assertEquals('value1', $params[$param1]);
        $this->assertEquals('value2', $params[$param2]);
    }
}