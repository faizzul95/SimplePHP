<?php

declare(strict_types=1);

namespace Tests\Unit\Components;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rule parameters were split on commas unconditionally, so a bounded quantifier such
 * as {2,3} truncated the pattern. The invalid-pattern guard then rejected everything,
 * meaning any regex rule containing a comma could never pass.
 */
final class RegexRuleTest extends TestCase
{
    private function passes(string $rule, string $value): bool
    {
        return validator(['field' => $value], ['field' => $rule])->validate()->passed();
    }

    /** @return list<array{0:string,1:string,2:bool}> */
    public static function quantifierProvider(): array
    {
        return [
            ['regex:/^[A-Z]{2,3}$/',    'ABC',    true],
            ['regex:/^[A-Z]{2,3}$/',    'ABCD',   false],
            ['regex:/^\d{4,}$/',        '12345',  true],
            ['regex:/^\d{4,}$/',        '123',    false],
            ['regex:/^[a-z]{3}$/',      'abc',    true],
            ['regex:/^A{1,2}B{1,2}$/',  'AAB',    true],
        ];
    }

    #[DataProvider('quantifierProvider')]
    public function testBoundedQuantifiersSurviveParameterParsing(string $rule, string $value, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->passes($rule, $value),
            sprintf('%s against "%s"', $rule, $value)
        );
    }

    /**
     * The string form splits on '|' before parameters are parsed, so a pattern with an
     * alternation has to be passed as an array element — the same escape hatch Laravel uses.
     */
    public function testAPatternContainingAPipeNeedsTheArrayForm(): void
    {
        $rules = ['field' => ['regex:/^(cat|dog)$/']];

        self::assertTrue(validator(['field' => 'cat'], $rules)->validate()->passed());
        self::assertFalse(validator(['field' => 'bird'], $rules)->validate()->passed());
    }

    public function testArrayFormAcceptsMultipleRules(): void
    {
        $rules = ['field' => ['required', 'string', 'regex:/^(cat|dog)$/']];

        self::assertTrue(validator(['field' => 'dog'], $rules)->validate()->passed());
        self::assertFalse(validator(['field' => ''], $rules)->validate()->passed());
    }

    public function testNotRegexAlsoKeepsItsCommas(): void
    {
        self::assertTrue($this->passes('not_regex:/^[0-9]{2,4}$/', 'abc'));
        self::assertFalse($this->passes('not_regex:/^[0-9]{2,4}$/', '123'));
    }

    public function testOtherRulesStillSplitOnCommas(): void
    {
        // in: and between: rely on the comma split, so the exemption must be narrow.
        self::assertTrue($this->passes('in:red,green,blue', 'green'));
        self::assertFalse($this->passes('in:red,green,blue', 'purple'));
        self::assertTrue($this->passes('between:2,5', '3'));
        self::assertFalse($this->passes('between:2,5', '9'));
    }

    public function testAPatternWithoutCommasStillWorks(): void
    {
        self::assertTrue($this->passes('regex:/^[0-9]{6}$/', '123456'));
        self::assertFalse($this->passes('regex:/^[0-9]{6}$/', '12345'));
    }
}
