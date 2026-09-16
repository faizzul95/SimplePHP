<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * attempt() guarded the account-status check with array_key_exists(), so a mistyped
 * user_status_column silently skipped enforcement and disabled accounts could sign in.
 *
 * Driving attempt() needs a database, so this asserts on the method source.
 */
final class AccountStatusFailClosedTest extends TestCase
{
    private function attemptSource(): string
    {
        $method = new ReflectionMethod(\Components\Auth::class, 'attempt');
        $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    public function testAMissingStatusColumnBlocksTheLogin(): void
    {
        $source = $this->attemptSource();

        self::assertMatchesRegularExpression(
            '/if \(!array_key_exists\(\$statusColumn, \$user\)\) \{/',
            $source,
            'attempt() no longer fails closed when the configured status column is absent, '
            . 'so a config typo lets disabled accounts sign in.'
        );
    }

    public function testTheFailClosedBranchReturnsFalse(): void
    {
        $source = $this->attemptSource();

        $branch = strpos($source, 'if (!array_key_exists($statusColumn, $user))');
        self::assertNotFalse($branch);

        $block = substr($source, $branch, 900);

        self::assertStringContainsString('return false;', $block, 'The branch must deny the login.');
        self::assertStringContainsString('account_status_unverifiable', $block);
    }

    public function testTheMisconfigurationIsLoggedWithTheColumnName(): void
    {
        $source = $this->attemptSource();
        $branch = (int) strpos($source, 'if (!array_key_exists($statusColumn, $user))');
        $block = substr($source, $branch, 900);

        self::assertStringContainsString('log_error', $block, 'A silent denial is as hard to debug as a silent pass.');
        self::assertStringContainsString('user_status_column', $block, 'The log should name the setting to fix.');
    }

    public function testEnforcementIsStillOptional(): void
    {
        // Deployments that genuinely have no status column can turn the policy off.
        self::assertStringContainsString(
            "\$policy['enforce_user_status'] ?? true",
            $this->attemptSource()
        );
    }
}
