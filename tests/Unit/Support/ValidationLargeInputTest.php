<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Components\Validation;
use PHPUnit\Framework\TestCase;

/**
 * The validator has to survive the input it exists to reject.
 *
 * `deep_array` protects against over-nested input, and measured the depth by
 * recursing once per level — so a hundred-thousand-level array, a few hundred
 * bytes of JSON, exhausted the PHP stack *before* the guard could fire. A stack
 * overflow is fatal, not an Exception, so the surrounding catch never saw it
 * either: the guard was defeated by its own implementation.
 *
 * `array_keys` compared every key against every allowed key, and `json`
 * allocated an entire decoded tree only to throw it away.
 */
final class ValidationLargeInputTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private function passes(array $data, array $rules): bool
    {
        return (new Validation())->setData($data)->setRules($rules)->validate()->passed();
    }

    /** Build $depth levels of nesting without recursing to do it. */
    private function nest(int $depth): array
    {
        $node = ['leaf' => true];

        for ($i = 0; $i < $depth; $i++) {
            $node = ['child' => $node];
        }

        return $node;
    }

    // ─── deep_array ──────────────────────────────────────────────────

    public function testAShallowArrayPasses(): void
    {
        self::assertTrue($this->passes(['payload' => $this->nest(3)], ['payload' => 'deep_array:10']));
    }

    public function testAnArrayPastTheDepthLimitIsRejected(): void
    {
        self::assertFalse($this->passes(['payload' => $this->nest(20)], ['payload' => 'deep_array:10']));
    }

    public function testTheBoundaryDepthIsAccepted(): void
    {
        // nest(9) plus the root is exactly 10 levels.
        self::assertTrue($this->passes(['payload' => $this->nest(9)], ['payload' => 'deep_array:10']));
    }

    /**
     * The one this file exists for. Recursive measurement died here; an explicit
     * stack returns an answer.
     */
    public function testAnAbsurdlyDeepArrayIsRejectedRatherThanCrashing(): void
    {
        self::assertFalse(
            $this->passes(['payload' => $this->nest(50000)], ['payload' => 'deep_array:10']),
            'A deeply nested payload must be refused, not exhaust the stack.'
        );
    }

    /** And it has to be refused quickly — walking 50k levels to say no is its own problem. */
    public function testTheDepthCheckShortCircuits(): void
    {
        $started = microtime(true);
        $this->passes(['payload' => $this->nest(50000)], ['payload' => 'deep_array:5']);
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertLessThan(500, $elapsed, 'The walk should stop once the limit is exceeded.');
    }

    public function testTheElementCeilingStillApplies(): void
    {
        $wide = ['payload' => range(1, 2000)];

        self::assertFalse($this->passes($wide, ['payload' => 'deep_array:10']));
        self::assertTrue($this->passes($wide, ['payload' => 'deep_array:10,5000']));
    }

    public function testANonArrayIsRejected(): void
    {
        self::assertFalse($this->passes(['payload' => 'not an array'], ['payload' => 'deep_array:10']));
    }

    // ─── array_keys ──────────────────────────────────────────────────

    public function testOnlyAllowedKeysPass(): void
    {
        self::assertTrue($this->passes(
            ['filter' => ['name' => 'a', 'email' => 'b']],
            ['filter' => 'array_keys:name,email,status']
        ));
    }

    public function testAnUnexpectedKeyIsRejected(): void
    {
        self::assertFalse($this->passes(
            ['filter' => ['name' => 'a', 'is_admin' => true]],
            ['filter' => 'array_keys:name,email']
        ));
    }

    /**
     * PHP normalises a numeric array key to an integer, and in_array()'s loose
     * default then matched 0 against any non-numeric allowed key — so a
     * positional array slipped past a rule naming only string keys.
     */
    public function testANumericKeyDoesNotLooselyMatchAStringKey(): void
    {
        self::assertFalse($this->passes(
            ['filter' => ['first', 'second']],
            ['filter' => 'array_keys:name,email']
        ));
    }

    public function testAnExplicitlyAllowedNumericKeyStillPasses(): void
    {
        self::assertTrue($this->passes(
            ['filter' => ['first', 'second']],
            ['filter' => 'array_keys:0,1']
        ));
    }

    public function testNoAllowedKeysMeansNoRestriction(): void
    {
        self::assertTrue($this->passes(['filter' => ['anything' => 1]], ['filter' => 'array_keys']));
    }

    /** A wide payload must not cost keys × allowed comparisons. */
    public function testAWidePayloadIsCheckedInOnePass(): void
    {
        $allowed = [];
        $payload = [];

        for ($i = 0; $i < 500; $i++) {
            $allowed[] = 'key_' . $i;
            $payload['key_' . $i] = $i;
        }

        $started = microtime(true);
        $result = $this->passes(['filter' => $payload], ['filter' => 'array_keys:' . implode(',', $allowed)]);
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertTrue($result);
        self::assertLessThan(250, $elapsed);
    }

    // ─── json ────────────────────────────────────────────────────────

    public function testValidJsonPasses(): void
    {
        self::assertTrue($this->passes(['payload' => '{"a":1,"b":[2,3]}'], ['payload' => 'json']));
    }

    public function testMalformedJsonIsRejected(): void
    {
        self::assertFalse($this->passes(['payload' => '{"a":1,'], ['payload' => 'json']));
        self::assertFalse($this->passes(['payload' => 'not json at all'], ['payload' => 'json']));
    }

    public function testANonStringIsNotValidJson(): void
    {
        self::assertFalse($this->passes(['payload' => ['a' => 1]], ['payload' => 'json']));
    }

    /**
     * Validating a large document must not cost its decoded size in memory.
     * json_validate() answers without building the tree; the pre-8.3 fallback
     * still decodes, so the assertion is generous.
     */
    public function testALargeDocumentIsValidatedWithoutHoldingItTwice(): void
    {
        $rows = [];
        for ($i = 0; $i < 20000; $i++) {
            $rows[] = ['id' => $i, 'name' => 'row ' . $i, 'tags' => ['a', 'b', 'c']];
        }

        $json = (string) json_encode($rows);
        self::assertGreaterThan(1_000_000, strlen($json));

        $before = memory_get_usage(true);
        $result = $this->passes(['payload' => $json], ['payload' => 'json']);
        $growth = memory_get_usage(true) - $before;

        self::assertTrue($result);

        if (function_exists('json_validate')) {
            self::assertLessThan(
                strlen($json),
                $growth,
                'json_validate() should not allocate the decoded structure.'
            );
        }
    }
}
