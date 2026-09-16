<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\FormRequest;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The validator resolves dotted and wildcard rule keys correctly, but the DTO was
 * built with a flat array_key_exists() against rules(), so nested payloads validated
 * and then vanished from validated() and every write below it.
 */
final class ValidatedNestedDataTest extends TestCase
{
    private function requestWith(array $input): Request
    {
        $_POST = $input;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        return Request::capture();
    }

    private function formRequest(array $rules, array $input): FormRequest
    {
        $form = new class ($rules) extends FormRequest {
            /** @param array<string,string> $ruleSet */
            public function __construct(private array $ruleSet)
            {
            }

            public function authorize(): bool
            {
                return true;
            }

            public function rules(): array
            {
                return $this->ruleSet;
            }
        };

        $form->setRequest($this->requestWith($input));
        $form->validateResolved();

        return $form;
    }

    public function testAFlatRuleStillWorks(): void
    {
        $form = $this->formRequest(['name' => 'required|string'], ['name' => 'Alya', 'extra' => 'x']);

        self::assertSame(['name' => 'Alya'], $form->validated());
    }

    public function testADottedRuleKeepsTheNestedValue(): void
    {
        $form = $this->formRequest(
            ['address.city' => 'required|string'],
            ['address' => ['city' => 'Kuala Lumpur', 'secret' => 'do-not-copy']]
        );

        self::assertSame(['address' => ['city' => 'Kuala Lumpur']], $form->validated());
    }

    public function testADottedRuleDoesNotDragSiblingsThrough(): void
    {
        // The allowlist has to stay an allowlist — this is the mass-assignment guard.
        $form = $this->formRequest(
            ['address.city' => 'required|string'],
            ['address' => ['city' => 'KL', 'is_admin' => true]]
        );

        self::assertArrayNotHasKey('is_admin', $form->validated()['address']);
    }

    public function testAWildcardRuleKeepsEveryMatchingLeaf(): void
    {
        $form = $this->formRequest(
            ['items.*.qty' => 'required|integer'],
            ['items' => [
                ['qty' => 2, 'price' => 100],
                ['qty' => 5, 'price' => 250],
            ]]
        );

        self::assertSame(
            ['items' => [['qty' => 2], ['qty' => 5]]],
            $form->validated()
        );
    }

    public function testTwoRulesOnTheSameParentMerge(): void
    {
        $form = $this->formRequest(
            ['address.city' => 'required|string', 'address.postcode' => 'required|string'],
            ['address' => ['city' => 'KL', 'postcode' => '50000', 'secret' => 'x']]
        );

        self::assertSame(
            ['address' => ['city' => 'KL', 'postcode' => '50000']],
            $form->validated()
        );
    }

    public function testAMissingNestedKeyIsSimplyAbsent(): void
    {
        $form = $this->formRequest(
            ['address.city' => 'nullable|string'],
            ['address' => ['postcode' => '50000']]
        );

        self::assertSame([], $form->validated());
    }

    public function testANonArrayParentIsNotCoerced(): void
    {
        $form = $this->formRequest(['address.city' => 'nullable|string'], ['address' => 'not-an-array']);

        self::assertSame([], $form->validated());
    }

    public function testDeepNestingIsPreserved(): void
    {
        $form = $this->formRequest(
            ['a.b.c' => 'required|string'],
            ['a' => ['b' => ['c' => 'deep', 'd' => 'drop'], 'e' => 'drop']]
        );

        self::assertSame(['a' => ['b' => ['c' => 'deep']]], $form->validated());
    }
}
