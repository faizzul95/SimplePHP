<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Telemetry\Bar;
use PHPUnit\Framework\TestCase;

final class BarTest extends TestCase
{
    private function bar(array $config = []): Bar
    {
        return new Bar($config);
    }

    private function page(string $body = '<h1>Hi</h1>'): string
    {
        return "<!doctype html><html><head><title>T</title></head><body>{$body}</body></html>";
    }

    public function testTheBarIsInsertedBeforeTheClosingBodyTag(): void
    {
        $html = $this->bar()->inject($this->page(), 'req-1');

        self::assertStringContainsString('myth-telemetry-bar', $html);
        self::assertLessThan(
            strripos($html, '</body>'),
            strpos($html, 'myth-telemetry-bar'),
            'the bar must sit inside the body'
        );
    }

    public function testTheOriginalMarkupIsPreserved(): void
    {
        $html = $this->bar()->inject($this->page('<h1>Keep me</h1>'), 'req-1');

        self::assertStringContainsString('<h1>Keep me</h1>', $html);
        self::assertStringContainsString('<title>T</title>', $html);
    }

    /** A JSON body or a partial has no </body>; it must come back untouched. */
    public function testAFragmentWithoutABodyTagIsUntouched(): void
    {
        $fragment = '<tr><td>row</td></tr>';

        self::assertSame($fragment, $this->bar()->inject($fragment, 'req-1'));
        self::assertSame('', $this->bar()->inject('', 'req-1'));
        self::assertSame('{"a":1}', $this->bar()->inject('{"a":1}', 'req-1'));
    }

    public function testInjectingTwiceDoesNotDuplicateTheBar(): void
    {
        $once = $this->bar()->inject($this->page(), 'req-1');
        $twice = $this->bar()->inject($once, 'req-1');

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, 'id="myth-telemetry-bar"'));
    }

    /** It goes before the last </body>, not a literal one inside page content. */
    public function testTheLastClosingBodyTagIsTheOneUsed(): void
    {
        $html = $this->bar()->inject(
            '<!doctype html><html><body><pre>&lt;/body&gt;</pre><p>x</p></body></html>',
            'req-1'
        );

        self::assertSame(1, substr_count($html, 'id="myth-telemetry-bar"'));
        self::assertStringEndsWith('</body></html>', $html);
    }

    // ─── Escaping ────────────────────────────────────────────────────

    /** The request id reaches an HTML attribute. */
    public function testTheRequestIdIsEscaped(): void
    {
        $html = $this->bar()->inject($this->page(), '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testTheEndpointIsEscaped(): void
    {
        $html = $this->bar(['endpoint' => '/x" onload="alert(1)'])->inject($this->page(), 'req-1');

        self::assertStringNotContainsString('onload="alert(1)"', $html);
    }

    public function testTheEndpointIsNormalisedToALeadingSlash(): void
    {
        $html = $this->bar(['endpoint' => '_custom/feed'])->inject($this->page(), 'req-1');

        self::assertStringContainsString('data-endpoint="/_custom/feed"', $html);
    }

    public function testTheDefaultEndpointIsUsedWhenNoneIsConfigured(): void
    {
        self::assertStringContainsString(
            'data-endpoint="/_telemetry/entries"',
            $this->bar()->inject($this->page(), 'req-1')
        );
    }

    /** It renders hidden; the JS decides whether to open it. */
    public function testTheBarStartsHidden(): void
    {
        self::assertStringContainsString('hidden>', $this->bar()->render('req-1'));
    }
}
