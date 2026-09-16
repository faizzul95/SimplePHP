<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nothing in the root .htaccess matched .env, and the final rewrite is guarded by
 * "!-f" which is false for a file that exists, so GET /.env was served verbatim.
 * public/upload/.htaccess meanwhile set "Options +Indexes", publishing an index
 * of every uploaded file. These parse the rule files rather than starting Apache.
 */
final class DeploymentHardeningTest extends TestCase
{
    private string $root;
    private string $rootHtaccess;
    private string $uploadHtaccess;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->rootHtaccess = (string) file_get_contents($this->root . '/.htaccess');
        $this->uploadHtaccess = (string) file_get_contents($this->root . '/public/upload/.htaccess');
    }

    /**
     * Apache ignores any line beginning with '#', so a commented-out directive is
     * inert. Strip comments before parsing, otherwise a rule someone disabled still
     * reads as active and these tests would pass while .env was being served.
     */
    private function activeDirectives(): string
    {
        $lines = preg_split('/\r?\n/', $this->rootHtaccess) ?: [];

        $active = array_filter(
            $lines,
            static fn(string $line): bool => !str_starts_with(ltrim($line), '#')
        );

        return implode("\n", $active);
    }

    /** @return list<string> every active <FilesMatch> pattern, as a PCRE */
    private function denyPatterns(): array
    {
        preg_match_all('/<FilesMatch "([^"]+)">/', $this->activeDirectives(), $matches);

        return array_map(static fn(string $p): string => '#' . $p . '#', $matches[1]);
    }

    private function isDenied(string $filename): bool
    {
        foreach ($this->denyPatterns() as $pattern) {
            if (preg_match($pattern, $filename) === 1) {
                return true;
            }
        }

        return false;
    }

    public function testEveryDenyPatternIsAValidRegex(): void
    {
        $patterns = $this->denyPatterns();
        self::assertNotEmpty($patterns, 'The root .htaccess declares no <FilesMatch> deny rules.');

        foreach ($patterns as $pattern) {
            self::assertNotFalse(
                @preg_match($pattern, ''),
                "Invalid regex in a <FilesMatch> directive: {$pattern}"
            );
        }
    }

    /**
     * @return list<array{0:string}>
     */
    public static function sensitiveFileProvider(): array
    {
        return [
            ['.env'],
            ['.env.example'],
            ['.env.production'],
            ['.env.local'],
            ['.gitignore'],
            ['.gitattributes'],
            ['.htaccess'],
            ['composer.json'],
            ['composer.lock'],
            ['phpunit.xml.dist'],
            ['phpstan.neon'],
            ['Caddyfile'],
            ['.rr.yaml'],
            ['myth'],
            ['example_db.sql'],
            ['seed_large_data.sql'],
            ['error.log'],
            ['backup.bak'],
        ];
    }

    #[DataProvider('sensitiveFileProvider')]
    public function testSensitiveFilesAreDenied(string $filename): void
    {
        self::assertTrue(
            $this->isDenied($filename),
            sprintf('"%s" is not denied by any <FilesMatch> rule and would be served as static text.', $filename)
        );
    }

    /**
     * @return list<array{0:string}>
     */
    public static function publicAssetProvider(): array
    {
        return [
            ['index.php'],
            ['style.css'],
            ['app.js'],
            ['logo.png'],
            ['photo.jpg'],
            ['icon.svg'],
            ['favicon.ico'],
            ['font.woff2'],
            ['report.pdf'],
            ['acme-challenge-token'],
        ];
    }

    #[DataProvider('publicAssetProvider')]
    public function testLegitimatePublicAssetsAreStillServed(string $filename): void
    {
        self::assertFalse(
            $this->isDenied($filename),
            sprintf('"%s" is blocked by a deny rule — the hardening is too broad.', $filename)
        );
    }

    /**
     * The critical structural property: if these rules sat inside the rewrite guard,
     * a host without mod_rewrite would serve everything.
     */
    public function testDenyRulesSitOutsideTheModRewriteGuard(): void
    {
        // Match the directive at the start of a line, not a mention of it in a comment.
        self::assertSame(
            1,
            preg_match('/^[ \t]*<IfModule mod_rewrite\.c>/m', $this->rootHtaccess, $guard, PREG_OFFSET_CAPTURE),
            'Expected an <IfModule mod_rewrite.c> block.'
        );
        $guardStart = $guard[0][1];

        self::assertSame(
            1,
            preg_match('/^[ \t]*<FilesMatch/m', $this->rootHtaccess, $deny, PREG_OFFSET_CAPTURE),
            'Expected at least one <FilesMatch> deny rule.'
        );
        $firstDeny = $deny[0][1];

        self::assertLessThan(
            $guardStart,
            $firstDeny,
            'The <FilesMatch> deny rules are inside <IfModule mod_rewrite.c>. On a host without '
            . 'mod_rewrite they would silently do nothing and .env would be served.'
        );
    }

    public function testDenyRulesWorkOnApache22AndApache24(): void
    {
        self::assertStringContainsString('Require all denied', $this->activeDirectives(), 'Missing the Apache 2.4 form.');
        self::assertStringContainsString('Deny from all', $this->activeDirectives(), 'Missing the Apache 2.2 fallback.');
    }

    public function testDirectoryBrowsingIsDisabledAtTheRoot(): void
    {
        self::assertMatchesRegularExpression('/^\s*Options\s+-Indexes/mi', $this->activeDirectives());
    }

    public function testTheUploadDirectoryDoesNotPublishAnIndex(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/^\s*Options\s+\+Indexes/mi',
            $this->uploadHtaccess,
            'public/upload/.htaccess re-enables directory listing. Uploaded filenames are random, '
            . 'which is only protection while the index is not published.'
        );

        self::assertMatchesRegularExpression(
            '/^\s*Options\s+-Indexes/mi',
            $this->uploadHtaccess,
            'public/upload/.htaccess should explicitly disable directory listing.'
        );
    }

    public function testTheUploadDirectoryRefusesToExecutePhp(): void
    {
        self::assertStringContainsString(
            'RemoveHandler',
            $this->uploadHtaccess,
            'The upload directory should strip PHP handlers as defence in depth.'
        );
    }

    public function testSensitiveDirectoriesAreBlockedByRewrite(): void
    {
        foreach (['app', 'systems', 'storage', 'logs', 'tests', 'docs', 'vendor'] as $dir) {
            self::assertMatchesRegularExpression(
                '/RewriteRule \^\([^)]*\b' . preg_quote($dir, '/') . '\b[^)]*\)\//',
                $this->activeDirectives(),
                sprintf('The "%s" directory is not blocked by the folder RewriteRule.', $dir)
            );
        }
    }
}
