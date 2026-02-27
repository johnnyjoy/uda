<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\ListHandler;
use UDA\SQL\ParamBag;

class ListHandlerTest extends TestCase
{
    public function testNonEmptyListReturnsParameterizedPlaceholders(): void
    {
        $bag = new ParamBag();

        $result = ListHandler::handle($bag, ['a', 'b', 'c']);

        $this->assertEquals('(:p1, :p2, :p3)', $result);
        $this->assertEquals(['p1' => 'a', 'p2' => 'b', 'p3' => 'c'], $bag->getParams());
    }

    public function testSingleItemListReturnsSinglePlaceholder(): void
    {
        $bag = new ParamBag();

        $result = ListHandler::handle($bag, ['single']);

        $this->assertEquals('(:p1)', $result);
        $this->assertEquals(['p1' => 'single'], $bag->getParams());
    }

    public function testEmptyListReturnsOneEqualsZero(): void
    {
        $bag = new ParamBag();

        $result = ListHandler::handle($bag, []);

        $this->assertEquals('(1=0)', $result);
        $this->assertEquals([], $bag->getParams());
    }

    public function testIntegerListWorksCorrectly(): void
    {
        $bag = new ParamBag();

        $result = ListHandler::handle($bag, [1, 2, 3]);

        $this->assertEquals('(:p1, :p2, :p3)', $result);
        $this->assertEquals(['p1' => 1, 'p2' => 2, 'p3' => 3], $bag->getParams());
    }

    public function testMixedTypeListWorksCorrectly(): void
    {
        $bag = new ParamBag();

        $result = ListHandler::handle($bag, ['string', 42, true, null]);

        $this->assertEquals('(:p1, :p2, :p3, :p4)', $result);
        $this->assertEquals(['p1' => 'string', 'p2' => 42, 'p3' => true, 'p4' => null], $bag->getParams());
    }
}