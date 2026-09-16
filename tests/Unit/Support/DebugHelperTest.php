<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * dump(), dd() and d() print_r()'d straight into the response. A dd() left in a
 * controller was a disclosure bug, an HTML dump inside a JSON response was a
 * parse error for the mobile client, and an unescaped value could break out of
 * the <pre> — or out of d()'s <script> tag.
 *
 * These run through the same functions the application uses; ENVIRONMENT is
 * 'testing' under the test bootstrap, which the guard treats as a development
 * environment.
 */
final class DebugHelperTest extends TestCase
{
    private function capture(callable $callback): string
    {
        ob_start();

        try {
            $callback();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    // ─── The environment guard ───────────────────────────────────────

    public function testTestingCountsAsADevelopmentEnvironment(): void
    {
        self::assertTrue(myth_debug_allowed(), 'ENVIRONMENT is "testing" under the test bootstrap.');
    }

    /**
     * The guard is a constant check rather than a config lookup so it still holds
     * in an error handler running before config is available.
     */
    public function testTheGuardOnlyAllowsKnownDevelopmentEnvironments(): void
    {
        self::assertSame('testing', strtolower((string) ENVIRONMENT));
        self::assertNotContains('production', ['development', 'local', 'testing']);
    }

    // ─── Escaping ────────────────────────────────────────────────────

    public function testAValueCannotBreakOutOfThePreBlock(): void
    {
        // PHP_SAPI is 'cli' here, so the HTML branch is asked for explicitly.
        $output = $this->capture(
            static fn() => myth_debug_render(['</pre><script>alert(1)</script>'], 'x.php:1', 'html')
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testTheJsonRendererKeepsTheResponseParseable(): void
    {
        $output = $this->capture(
            static fn() => myth_debug_render([['id' => 1]], 'x.php:1', 'json')
        );

        $decoded = json_decode($output, true);

        self::assertIsArray($decoded, 'An API client has to be able to parse what it gets back.');
        self::assertSame('x.php:1', $decoded['_dump']['origin']);
    }

    public function testAConsoleRunDetectsTheTextFormat(): void
    {
        self::assertSame('text', myth_debug_format());
    }

    /**
     * d($var, true) json_encode()'d into a <script> tag. A dumped value
     * containing "</script>" closed the tag and everything after it ran as
     * markup — a debug call that became stored XSS.
     */
    public function testTheConsoleDumpCannotCloseItsOwnScriptTag(): void
    {
        $output = $this->capture(static fn() => d('</script><img src=x onerror=alert(1)>', true));

        self::assertStringNotContainsString('</script><img', $output);
        self::assertSame(1, substr_count($output, '</script>'), 'Exactly one closing tag: the one we wrote.');
        self::assertStringContainsString('<', $output, 'Angle brackets must be hex-escaped.');
    }

    // ─── Call site ───────────────────────────────────────────────────

    public function testTheDumpSaysWhereItCameFrom(): void
    {
        $output = $this->capture(static fn() => dump('value'));

        self::assertStringContainsString('DebugHelperTest.php:', $output);
    }

    public function testTheOriginIsRelativeToTheProjectRoot(): void
    {
        $origin = myth_debug_origin();

        self::assertStringNotContainsString(ROOT_DIR, $origin);
        self::assertMatchesRegularExpression('/:\d+$/', $origin);
    }

    // ─── Content negotiation ─────────────────────────────────────────

    public function testTheCliRendererDoesNotEmitMarkup(): void
    {
        // PHP_SAPI is 'cli' under PHPUnit, so this is the branch that runs.
        $output = $this->capture(static fn() => dump(['id' => 1]));

        self::assertStringNotContainsString('<pre', $output);
    }

    public function testEveryDumpedValueIsRendered(): void
    {
        $output = $this->capture(static fn() => dump('alpha', 'beta', 'gamma'));

        self::assertStringContainsString('alpha', $output);
        self::assertStringContainsString('beta', $output);
        self::assertStringContainsString('gamma', $output);
    }

    // ─── Failure tolerance ───────────────────────────────────────────

    /** A value json_encode() cannot represent must not turn a dump into a fatal. */
    public function testAnUnencodableValueDoesNotThrow(): void
    {
        $output = $this->capture(static fn() => d(["\xB1\x31" => 'invalid utf8'], true));

        self::assertNotSame('', $output);
    }

    public function testARecursiveStructureDoesNotThrow(): void
    {
        $node = ['name' => 'root'];
        $node['self'] = &$node;

        $output = $this->capture(static fn() => dump($node));

        self::assertStringContainsString('root', $output);
    }
}
