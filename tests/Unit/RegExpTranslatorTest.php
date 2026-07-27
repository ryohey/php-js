<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\RegExp\RegExpSyntaxError;
use PhpJs\RegExp\RegExpTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegExpTranslatorTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: bool}> pattern, flags, subject, matches */
    public static function matchCases(): iterable
    {
        yield 'simple' => ['a+b', '', 'aaab', true];
        yield 'anchors' => ['^ab$', '', 'ab', true];
        yield 'dollar no trailing newline' => ['^ab$', '', "ab\n", false];
        yield 'multiline dollar' => ['^b$', 'm', "a\nb", true];
        yield 'dot excludes newline' => ['a.b', '', "a\nb", false];
        yield 'dot matches unicode char' => ['a.b', '', 'aéb', true];
        yield 'ignore case' => ['HeLLo', 'i', 'hello', true];
        yield 'unicode escape' => ['\\u00e9', '', 'é', true];
        yield 'digit class' => ['^\\d+$', '', '123', true];
        yield 'negated empty class' => ['^[^]+$', '', "a\nb", true];
        yield 'slash in pattern' => ['a/b', '', 'a/b', true];
    }

    #[DataProvider('matchCases')]
    public function testTranslateAndMatch(string $pattern, string $flags, string $subject, bool $expected): void
    {
        $pcre = RegExpTranslator::translate($pattern, $flags);
        $this->assertSame($expected, preg_match($pcre, $subject) === 1, "$pattern with '$flags' vs '$subject' → $pcre");
    }

    public function testEmptyClassNeverMatches(): void
    {
        $pcre = RegExpTranslator::translate('[]', '');
        $this->assertSame(0, preg_match($pcre, 'anything'));
    }

    public function testStickyUsesAnchoredModifier(): void
    {
        $pcre = RegExpTranslator::translate('b', 'y');
        $this->assertStringContainsString('A', substr($pcre, strrpos($pcre, '/')));
    }

    public function testNamedGroupsRejected(): void
    {
        $this->expectException(RegExpSyntaxError::class);
        RegExpTranslator::translate('(?<name>a)', '');
    }

    public function testLookaheadPassesThrough(): void
    {
        $pcre = RegExpTranslator::translate('a(?=b)', '');
        $this->assertSame(1, preg_match($pcre, 'ab'));
        $this->assertSame(0, preg_match($pcre, 'ac'));
    }

    public function testInvalidFlagRejected(): void
    {
        $this->expectException(RegExpSyntaxError::class);
        RegExpTranslator::translate('a', 'z');
    }
}
