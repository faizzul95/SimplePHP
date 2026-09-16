<?php

declare(strict_types=1);

use Core\View\BladeEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Most directives were compiled with a naive non-greedy regex:
 *
 *     preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $content)
 *
 * `(.*?)\)` stops at the FIRST closing parenthesis, so every expression holding a
 * nested call was truncated mid-way:
 *
 *     @if(count($users) > 0)  ->  <?php if (count($users): ?> > 0)
 *
 * which is a PHP parse error. The bundled views contain no nested parentheses,
 * which is why the rest of the suite never caught it. The balanced-parenthesis
 * scanner used by @include/@section/@each now handles these too.
 */
final class BladeNestedExpressionTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $base = ROOT_DIR . 'storage/framework/testing/blade-nested';
        $this->viewDir = $base . '/views';
        $this->cacheDir = $base . '/cache';

        foreach ([$this->viewDir, $this->cacheDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewDir . '/*.php') ?: [] as $view) {
            unlink($view);
        }

        foreach (glob($this->cacheDir . '/*.php') ?: [] as $compiled) {
            unlink($compiled);
        }

        parent::tearDown();
    }

    private function render(string $template, array $data = []): string
    {
        $name = 'nested_' . (++$this->counter);
        file_put_contents($this->viewDir . '/' . $name . '.php', $template);

        return (new BladeEngine($this->viewDir, $this->cacheDir))->render($name, $data);
    }

    /** Compile without executing — for directives whose output needs runtime services. */
    private function compile(string $template): string
    {
        $engine = new BladeEngine($this->viewDir, $this->cacheDir);
        $method = new ReflectionMethod($engine, 'compileString');
        $method->setAccessible(true);

        return (string) $method->invoke($engine, $template);
    }

    /** @return array<string, array{0:string, 1:array<string,mixed>, 2:string}> */
    public static function nestedExpressionProvider(): array
    {
        return [
            '@if with a function call' => [
                '@if(count($users) > 0)HAS@endif',
                ['users' => [1, 2]],
                'HAS',
            ],
            '@if with negated empty()' => [
                '@if(!empty($users))HAS@endif',
                ['users' => [1]],
                'HAS',
            ],
            '@if false branch stays silent' => [
                '@if(count($users) > 0)HAS@endif',
                ['users' => []],
                '',
            ],
            '@elseif with a function call' => [
                '@if(count($users) > 5)MANY@elseif(count($users) > 0)SOME@endif',
                ['users' => [1, 2]],
                'SOME',
            ],
            '@unless with a function call' => [
                '@unless(count($users) > 0)NONE@endunless',
                ['users' => []],
                'NONE',
            ],
            '@isset on a nested key' => [
                '@isset($payload["a"]["b"])SET@endisset',
                ['payload' => ['a' => ['b' => 1]]],
                'SET',
            ],
            '@empty with a function call' => [
                '@empty(array_filter($users))NONE@endempty',
                ['users' => [0, null]],
                'NONE',
            ],
            '@foreach over a function result' => [
                '@foreach(array_keys($map) as $k){{ $k }}@endforeach',
                ['map' => ['x' => 1, 'y' => 2]],
                'xy',
            ],
            '@for with a count() bound' => [
                '@for($i = 0; $i < count($users); $i++)#@endfor',
                ['users' => [1, 2, 3]],
                '###',
            ],
            '@while with a function call' => [
                '@while(count($stack) > 0)@php array_pop($stack); @endphp#@endwhile',
                ['stack' => [1, 2]],
                '##',
            ],
            '@switch / @case / @break' => [
                '@switch(strtolower($role))@case("admin")ADMIN@break@case("user")USER@break@endswitch',
                ['role' => 'USER'],
                'USER',
            ],
            '@class with a nested condition' => [
                '<div class="@class([\'on\' => count($users) > 0, \'off\' => count($users) === 0])"></div>',
                ['users' => [1]],
                'class="on"',
            ],
            '@style with a nested condition' => [
                '<div style="@style([\'display:none\' => empty($users)])"></div>',
                ['users' => []],
                'style="display:none"',
            ],
            '@checked with a nested call' => [
                '<input @checked(in_array(1, $ids, true))>',
                ['ids' => [1, 2]],
                '<input checked>',
            ],
            '@json of a function result' => [
                '@json(array_values($map))',
                ['map' => ['a' => 1, 'b' => 2]],
                '[1,2]',
            ],
        ];
    }

    #[DataProvider('nestedExpressionProvider')]
    public function testNestedParenthesesCompileCorrectly(string $template, array $data, string $expected): void
    {
        self::assertStringContainsString($expected, $this->render($template, $data));
    }

    public function testSimpleExpressionsStillCompile(): void
    {
        self::assertStringContainsString(
            'YES',
            $this->render('@if($flag)YES@else NO@endif', ['flag' => true])
        );
    }

    public function testWhitespaceBetweenDirectiveAndParenIsTolerated(): void
    {
        self::assertStringContainsString(
            'HAS',
            $this->render('@if (count($users) > 0)HAS@endif', ['users' => [1]])
        );
    }

    public function testForDirectiveDoesNotSwallowForeach(): void
    {
        self::assertStringContainsString(
            'ab',
            $this->render('@foreach($items as $i){{ $i }}@endforeach', ['items' => ['a', 'b']])
        );
    }

    public function testCanDirectiveDoesNotSwallowCannot(): void
    {
        $compiled = $this->compile('@cannot("edit", $post)NOPE@endcannot');

        self::assertStringContainsString('auth()->cannot("edit", $post)', $compiled);
        self::assertStringNotContainsString('@cannot', $compiled);
    }

    public function testCanCompilesANestedCallArgument(): void
    {
        $compiled = $this->compile('@can("edit", $posts->first())OK@endcan');

        self::assertStringContainsString('auth()->can("edit", $posts->first())', $compiled);
    }

    public function testDumpCompilesANestedCallArgument(): void
    {
        $compiled = $this->compile('@dump(array_keys($map))');

        self::assertStringContainsString('renderDebugDump([array_keys($map)], false)', $compiled);
    }

    public function testBareBreakAndContinueCompile(): void
    {
        $compiled = $this->compile('@foreach($a as $b)@continue@break@endforeach');

        self::assertStringContainsString('continue;', $compiled);
        self::assertStringContainsString('break;', $compiled);
        self::assertStringNotContainsString('@break', $compiled);
        self::assertStringNotContainsString('@continue', $compiled);
    }

    public function testConditionalBreakStillCompiles(): void
    {
        $compiled = $this->compile('@foreach($a as $b)@break(count($b) > 2)@endforeach');

        self::assertStringContainsString('if (count($b) > 2) break;', $compiled);
    }

    public function testVerbatimBlockKeepsDirectivesLiteral(): void
    {
        self::assertStringContainsString(
            '@if(count($x) > 0)',
            $this->render('@verbatim@if(count($x) > 0)@endif@endverbatim')
        );
    }

    public function testUnbalancedParenthesesAreLeftAloneRatherThanMiscompiled(): void
    {
        // Better to render the literal directive than to emit broken PHP.
        self::assertStringContainsString('@if(count($x', $this->render('@if(count($x'));
    }
}
