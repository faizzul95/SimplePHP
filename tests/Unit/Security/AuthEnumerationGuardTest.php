<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A correct DUMMY_HASH is half the fix; attempt() has to call dummyVerify() when
 * the lookup misses, and it did not. Exercising attempt() needs a database, so
 * these assert on the method source instead.
 */
final class AuthEnumerationGuardTest extends TestCase
{
    private function attemptSource(): string
    {
        $method = new ReflectionMethod(\Components\Auth::class, 'attempt');
        $file   = $method->getFileName();

        self::assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return implode("\n", array_slice($lines, $start, $length));
    }

    public function testAttemptBurnsAHashWhenTheUserIsNotFound(): void
    {
        $source = $this->attemptSource();

        self::assertMatchesRegularExpression(
            '/Hasher::dummyVerify\s*\(/',
            $source,
            'Components\Auth::attempt() no longer calls Hasher::dummyVerify(). Without it the '
            . '"user not found" branch returns in microseconds while a real verification costs '
            . '~200 ms, which is an account-enumeration oracle.'
        );
    }

    public function testTheDummyVerifyCallSitsOnTheUserNotFoundBranch(): void
    {
        $source = $this->attemptSource();

        $branchPos = strpos($source, 'if (empty($user))');
        self::assertNotFalse($branchPos, 'Expected an "if (empty($user))" branch in attempt().');

        $dummyPos = strpos($source, 'Hasher::dummyVerify');
        self::assertNotFalse($dummyPos);

        self::assertGreaterThan(
            $branchPos,
            $dummyPos,
            'dummyVerify() must be called inside the user-not-found branch, not before the lookup.'
        );

        $verifyPos = strpos($source, 'Hasher::verify');
        self::assertNotFalse($verifyPos, 'Expected the real Hasher::verify() call to still be present.');

        self::assertLessThan(
            $verifyPos,
            $dummyPos,
            'The dummy verification should precede the real one — it belongs on the early-return path.'
        );
    }

    public function testTheOnlyOtherKnownCallSiteStillExists(): void
    {
        // AccessCredentialService covers the API-token credential path. If this
        // disappears, the API login regains the timing gap the web login just lost.
        $path = dirname(__DIR__, 3) . '/app/support/Auth/AccessCredentialService.php';

        if (!is_file($path)) {
            self::markTestSkipped('AccessCredentialService has moved — update this guard.');
        }

        self::assertStringContainsString(
            'dummyVerify',
            (string) file_get_contents($path),
            'AccessCredentialService no longer performs a dummy verification on credential miss.'
        );
    }
}
