<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UDA\SQL\Keyword;
use UDA\SQL\InvalidKeywordException;

class KeywordTest extends TestCase
{
    public function testValidKeywordPassesValidation(): void
    {
        $keyword = new Keyword('SELECT');

        $this->assertEquals('SELECT', $keyword->getToken());
    }

    public function testLowercaseKeywordIsConvertedToUppercase(): void
    {
        $keyword = new Keyword('select');

        $this->assertEquals('SELECT', $keyword->getToken());
    }

    public function testInvalidKeywordThrowsException(): void
    {
        $this->expectException(InvalidKeywordException::class);

        new Keyword('INVALID_KEYWORD');
    }

    public function testDangerousKeywordThrowsException(): void
    {
        $this->expectException(InvalidKeywordException::class);

        new Keyword('DROP');
    }

    public function testKeywordCanBeConvertedToString(): void
    {
        $keyword = new Keyword('WHERE');

        $this->assertEquals('WHERE', (string) $keyword);
    }
}