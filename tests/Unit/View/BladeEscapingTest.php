<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use Core\View\BladeEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {{ }} compiled to htmlspecialchars($v, ENT_QUOTES, 'UTF-8').
 *
 * Passing the flags explicitly opts out of PHP 8.1's default ENT_SUBSTITUTE, and
 * without it htmlspecialchars returns an empty string for any input containing an
 * invalid UTF-8 byte. So a name pasted from a Latin-1 source, a filename off a
 * Windows share, a truncated multibyte character — each rendered as nothing at
 * all, with no error anywhere. ENT_SUBSTITUTE renders U+FFFD instead and keeps
 * the rest of the value.
 */
final class BladeEscapingTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $base = ROOT_DIR . 'storage/framework/testing/blade-escaping';
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
        foreach ([$this->viewDir, $this->cacheDir] as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = []): string
    {
        $name = 'escaping_' . (++$this->counter);
        file_put_contents($this->viewDir . '/' . $name . '.php', $template);

        return (new BladeEngine($this->viewDir, $this->cacheDir))->render($name, $data);
    }

    // ─── Escaping still works ────────────────────────────────────────

    /** @return array<string, array{0:string, 1:string}> */
    public static function escapedProvider(): array
    {
        return [
            'script tag'   => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'double quote' => ['" onmouseover="alert(1)', '&quot; onmouseover=&quot;alert(1)'],
            'single quote' => ["' onload='alert(1)", '&#039; onload=&#039;alert(1)'],
            'ampersand'    => ['Tom & Jerry', 'Tom &amp; Jerry'],
        ];
    }

    #[DataProvider('escapedProvider')]
    public function testMarkupIsEscaped(string $input, string $expected): void
    {
        self::assertSame($expected, $this->render('{{ $value }}', ['value' => $input]));
    }

    /** ENT_QUOTES covers the single quote, which an attribute without double quotes needs. */
    public function testBothQuoteStylesAreEscaped(): void
    {
        $output = $this->render('<a title={{ $value }}>x</a>', ['value' => '\'">']);

        self::assertStringNotContainsString('\'', $output);
        self::assertStringNotContainsString('">', $output);
    }

    // ─── Invalid UTF-8 ───────────────────────────────────────────────

    /**
     * The bug this file exists for: an invalid byte used to blank the entire
     * value, so a user whose name came in as Latin-1 rendered as an empty cell.
     */
    public function testInvalidUtf8DoesNotBlankTheWholeValue(): void
    {
        // "Jos<0xE9> Garcia" — a lone Latin-1 é.
        $output = $this->render('{{ $value }}', ['value' => "Jos\xE9 Garcia"]);

        self::assertNotSame('', $output);
        self::assertStringContainsString('Jos', $output);
        self::assertStringContainsString('Garcia', $output);
    }

    public function testTheInvalidByteIsReplacedRatherThanPassedThrough(): void
    {
        $output = $this->render('{{ $value }}', ['value' => "bad\xE9byte"]);

        self::assertStringNotContainsString("\xE9", $output);
        self::assertStringContainsString("\u{FFFD}", $output);
    }

    public function testATruncatedMultibyteCharacterStillRenders(): void
    {
        // First two bytes of a three-byte character.
        $output = $this->render('{{ $value }}', ['value' => "price: \xE2\x82"]);

        self::assertStringContainsString('price:', $output);
    }

    /**
     * The substitution must not be a way to smuggle markup: U+FFFD is not a
     * character any parser treats as syntax.
     */
    public function testSubstitutionCannotIntroduceMarkup(): void
    {
        $output = $this->render('{{ $value }}', ['value' => "\xE9<script>"]);

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }

    // ─── Raw output is still raw ─────────────────────────────────────

    /** {!! !!} is the documented escape hatch; it must keep working unchanged. */
    public function testRawOutputIsNotEscaped(): void
    {
        self::assertSame('<b>bold</b>', $this->render('{!! $value !!}', ['value' => '<b>bold</b>']));
    }

    // ─── Types ───────────────────────────────────────────────────────

    /** @return array<string, array{0:mixed, 1:string}> */
    public static function scalarProvider(): array
    {
        return [
            'integer' => [42, '42'],
            'float'   => [1.5, '1.5'],
            'true'    => [true, '1'],
            'false'   => [false, ''],
            'null'    => [null, ''],
        ];
    }

    #[DataProvider('scalarProvider')]
    public function testNonStringsAreCastBeforeEscaping(mixed $value, string $expected): void
    {
        self::assertSame($expected, $this->render('{{ $value }}', ['value' => $value]));
    }

    // ─── Compiled-cache survival ─────────────────────────────────────

    /**
     * The per-process memo maps a source path to its compiled file. It outlived
     * the file: run `view:clear` against a warm worker and every later render
     * included a path that no longer existed — no error, just a blank page,
     * until someone restarted the worker. Found by this suite deleting its own
     * compiled files between tests.
     */
    public function testARenderRecoversAfterTheCompiledFileIsDeleted(): void
    {
        $name = 'escaping_survive_' . bin2hex(random_bytes(4));
        file_put_contents($this->viewDir . '/' . $name . '.php', '{{ $value }}');

        $engine = new BladeEngine($this->viewDir, $this->cacheDir);
        self::assertSame('first', $engine->render($name, ['value' => 'first']));

        // What `php myth view:clear` does.
        foreach (glob($this->cacheDir . '/*.php') ?: [] as $compiled) {
            unlink($compiled);
        }

        self::assertSame('second', $engine->render($name, ['value' => 'second']));
    }
}
