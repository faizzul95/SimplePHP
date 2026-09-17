<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\RoleController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The datatable action column used to build inline onclick attributes, with the
 * interpolated value escaped by addslashes().
 *
 * That escapes for JavaScript, but the value lands in an HTML attribute first
 * and the HTML parser runs before the JS parser — a backslash means nothing to
 * it. A role named `Ops' onmouseover='alert(1)` therefore closed the attribute
 * and the remainder parsed as further attributes: stored XSS against any admin
 * who hovered the row.
 *
 * These assert against what a browser actually parses, not against the string,
 * because the string looked escaped.
 */
final class DatatableRowActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The row builder asks permission() what to render, which reaches for
        // auth(). Granting everything produces the richest markup — every
        // action button present — which is the widest surface to assert on.
        register_framework_service('auth', static fn(): object => new class () {
            public function can(string $ability): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        reset_framework_service('auth');
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function actionHtml(array $overrides = []): string
    {
        $row = array_merge([
            'id' => 7,
            'role_name' => 'Operations',
            'role_rank' => 10,
            'role_status' => 1,
            'profile_count' => 0,
        ], $overrides);

        $method = new \ReflectionMethod(RoleController::class, 'mapRoleDatatableRow');
        $controller = (new \ReflectionClass(RoleController::class))->newInstanceWithoutConstructor();

        /** @var array<string, mixed> $mapped */
        $mapped = $method->invoke($controller, $row);

        return (string) $mapped['action'];
    }

    /** @return list<string> Attribute names a browser parses out of the markup. */
    private function parsedAttributes(string $html): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $names = [];
        $walk = static function (\DOMNode $node) use (&$walk, &$names): void {
            if ($node instanceof \DOMElement) {
                foreach ($node->attributes as $attribute) {
                    $names[] = strtolower($attribute->name);
                }
            }
            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return $names;
    }

    // ─── The attack ──────────────────────────────────────────────────

    #[DataProvider('injectionPayloads')]
    public function testARoleNameCannotIntroduceAnEventHandler(string $roleName): void
    {
        $attributes = $this->parsedAttributes($this->actionHtml(['role_name' => $roleName]));

        foreach ($attributes as $name) {
            self::assertStringStartsNotWith(
                'on',
                $name,
                'a role name introduced the attribute: ' . $name
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function injectionPayloads(): array
    {
        return [
            'apostrophe breakout' => ["Ops' onmouseover='alert(1)"],
            'double quote breakout' => ['Ops" onmouseover="alert(1)'],
            'escaped apostrophe' => ["Ops\\' onmouseover='alert(1)"],
            'tag breakout' => ['Ops><img src=x onerror=alert(1)>'],
            'plain apostrophe' => ["O'Brien's Team"],
        ];
    }

    /** No inline handler at all now, whatever the input. */
    public function testTheActionColumnEmitsNoInlineHandlers(): void
    {
        $html = $this->actionHtml();

        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onmouseover', $html);
    }

    // ─── The behaviour it replaced ───────────────────────────────────

    public function testTheActionColumnCarriesTheDataAttributesTheHandlerReads(): void
    {
        $html = $this->actionHtml();

        self::assertStringContainsString("data-dt-action='edit'", $html);
        self::assertStringContainsString("data-dt-id='7'", $html);
    }

    /** An apostrophe in a name must survive as data, entity-encoded. */
    public function testALegitimateApostropheIsPreservedAsText(): void
    {
        $html = $this->actionHtml(['role_name' => "O'Brien"]);

        $document = new \DOMDocument();
        @$document->loadHTML('<div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $found = false;
        foreach ($document->getElementsByTagName('a') as $anchor) {
            if ($anchor->getAttribute('data-dt-name') === "O'Brien") {
                $found = true;
            }
        }

        self::assertTrue($found, 'the name should round-trip through the attribute intact');
    }
}
