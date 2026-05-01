<?php

declare(strict_types=1);

use App\Support\Auth\TokenService;
use Components\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTokenLifecycleProbe extends Auth
{
    public ?array $resolvedUser = null;
    public ?string $resolvedBearerToken = null;

    public function bearerToken(): ?string
    {
        return $this->resolvedBearerToken;
    }

    public function user(array|string|null $methods = null): ?array
    {
        return $this->resolvedUser;
    }
}

final class RecordingTokenLifecycleService extends TokenService
{
    public array $listedForUsers = [];
    public array $currentTokenCalls = [];
    public array $rotateCalls = [];
    public array $tokensResponse = [];
    public ?array $currentTokenResponse = null;
    public ?string $rotateResponse = null;

    public function tokensForUser(int $userId, callable $safeColumn, callable $safeTable): array
    {
        $this->listedForUsers[] = $userId;
        return $this->tokensResponse;
    }

    public function currentToken(string $plainToken, callable $safeColumn, callable $safeTable): ?array
    {
        $this->currentTokenCalls[] = $plainToken;
        return $this->currentTokenResponse;
    }

    public function rotateToken(string $plainToken, string $name, ?int $expiresAt, array $abilities, callable $safeColumn, callable $safeTable): ?string
    {
        $this->rotateCalls[] = [
            'plain_token' => $plainToken,
            'name' => $name,
            'expires_at' => $expiresAt,
            'abilities' => $abilities,
        ];

        return $this->rotateResponse;
    }
}

final class AuthTokenLifecycleTest extends TestCase
{
    public function testTokensUsesCurrentResolvedUserWhenUserIdIsNotProvided(): void
    {
        $tokenService = new RecordingTokenLifecycleService();
        $tokenService->tokensResponse = [['id' => 10, 'name' => 'CLI']];
        $auth = new AuthTokenLifecycleProbe([], null, null, $tokenService);
        $auth->resolvedUser = ['id' => 44];

        $tokens = $auth->tokens();

        self::assertSame([44], $tokenService->listedForUsers);
        self::assertSame([['id' => 10, 'name' => 'CLI']], $tokens);
    }

    public function testCurrentTokenDelegatesBearerResolutionToTokenService(): void
    {
        $tokenService = new RecordingTokenLifecycleService();
        $tokenService->currentTokenResponse = ['id' => 9, 'name' => 'Mobile'];
        $auth = new AuthTokenLifecycleProbe([], null, null, $tokenService);
        $auth->resolvedBearerToken = '9|bearer-token';

        $current = $auth->currentToken();

        self::assertSame(['9|bearer-token'], $tokenService->currentTokenCalls);
        self::assertSame(9, $current['id']);
    }

    public function testRotateTokenDelegatesToTokenService(): void
    {
        $tokenService = new RecordingTokenLifecycleService();
        $tokenService->rotateResponse = '91|replacement-token';
        $auth = new AuthTokenLifecycleProbe([], null, null, $tokenService);

        $rotated = $auth->rotateToken('9|bearer-token', 'Rotated', 1893456000, ['reports.read']);

        self::assertSame('91|replacement-token', $rotated);
        self::assertCount(1, $tokenService->rotateCalls);
        self::assertSame('9|bearer-token', $tokenService->rotateCalls[0]['plain_token']);
        self::assertSame('Rotated', $tokenService->rotateCalls[0]['name']);
        self::assertSame(1893456000, $tokenService->rotateCalls[0]['expires_at']);
        self::assertSame(['reports.read'], $tokenService->rotateCalls[0]['abilities']);
    }
}