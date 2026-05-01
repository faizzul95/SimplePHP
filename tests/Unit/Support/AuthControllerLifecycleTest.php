<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class AuthControllerLifecycleAuthDouble
{
    public ?int $userId = 44;
    public ?string $sessionVia = 'session';
    public ?string $tokenVia = null;
    public array $sessionsResponse = [];
    public array $tokensResponse = [];
    public ?array $currentTokenResponse = null;
    public ?string $bearerTokenValue = null;
    public bool $revokeSessionResult = true;
    public bool $logoutOtherDevicesResult = true;
    public ?string $rotateTokenResponse = null;
    public array $sessionsCalls = [];
    public array $tokensCalls = [];
    public array $revokeSessionCalls = [];
    public array $logoutOtherDevicesCalls = [];
    public array $rotateTokenCalls = [];

    public function via(array|string|null $methods = null): ?string
    {
        if ($methods === null) {
            return $this->sessionVia ?? $this->tokenVia;
        }

        $normalized = is_array($methods) ? $methods : [$methods];
        $normalized = array_map(static fn($method) => strtolower(trim((string) $method)), $normalized);

        if (array_intersect($normalized, ['oauth', 'session']) !== []) {
            return $this->sessionVia;
        }

        if (array_intersect($normalized, ['token', 'oauth2', 'api_key', 'jwt', 'basic', 'digest']) !== []) {
            return $this->tokenVia;
        }

        return $this->sessionVia ?? $this->tokenVia;
    }

    public function id(array|string|null $methods = null): ?int
    {
        $via = $this->via($methods);
        return $via === null ? null : $this->userId;
    }

    public function sessions(?int $userId = null): array
    {
        $this->sessionsCalls[] = $userId;
        return $this->sessionsResponse;
    }

    public function revokeSession(string $sessionId): bool
    {
        $this->revokeSessionCalls[] = $sessionId;
        return $this->revokeSessionResult;
    }

    public function logoutOtherDevices(string $password): bool
    {
        $this->logoutOtherDevicesCalls[] = $password;
        return $this->logoutOtherDevicesResult;
    }

    public function tokens(?int $userId = null): array
    {
        $this->tokensCalls[] = $userId;
        return $this->tokensResponse;
    }

    public function currentToken(): ?array
    {
        return $this->currentTokenResponse;
    }

    public function bearerToken(): ?string
    {
        return $this->bearerTokenValue;
    }

    public function rotateToken(string $plainToken, string $name = '', ?int $expiresAt = null, array $abilities = []): ?string
    {
        $this->rotateTokenCalls[] = [
            'plain_token' => $plainToken,
            'name' => $name,
            'expires_at' => $expiresAt,
            'abilities' => $abilities,
        ];

        return $this->rotateTokenResponse;
    }
}

final class AuthControllerLifecycleTest extends TestCase
{
    private AuthControllerLifecycleAuthDouble $authDouble;

    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices();
        $this->authDouble = new AuthControllerLifecycleAuthDouble();
        reset_framework_service('auth');
        register_framework_service('auth', fn() => $this->authDouble);
    }

    public function testDevicesReturnsCurrentSessionRegistryForCurrentUser(): void
    {
        $this->authDouble->sessionsResponse = [['session_id' => 'abc', 'current' => true]];
        $controller = new AuthController();

        $response = $controller->devices(new Request([], [], ['REQUEST_URI' => '/api/v1/auth/devices'], []));

        self::assertSame(200, $response['code']);
        self::assertSame([44], $this->authDouble->sessionsCalls);
        self::assertSame('abc', $response['data'][0]['session_id']);
    }

    public function testRevokeDeviceReturnsNotFoundWhenSessionCannotBeRemoved(): void
    {
        $this->authDouble->revokeSessionResult = false;
        $controller = new AuthController();

        $response = $controller->revokeDevice('missing-session');

        self::assertSame(404, $response['code']);
        self::assertSame(['missing-session'], $this->authDouble->revokeSessionCalls);
    }

    public function testLogoutOtherDevicesRequiresPasswordAndDelegatesToAuth(): void
    {
        $controller = new AuthController();

        $missingPassword = $controller->logoutOtherDevices(new Request([], [], ['REQUEST_URI' => '/api/v1/auth/logout-other-devices'], []));
        self::assertSame(400, $missingPassword['code']);

        $response = $controller->logoutOtherDevices(new Request([], ['password' => 'secret-pass'], ['REQUEST_URI' => '/api/v1/auth/logout-other-devices'], []));

        self::assertSame(200, $response['code']);
        self::assertSame(['secret-pass'], $this->authDouble->logoutOtherDevicesCalls);
    }

    public function testTokensListsCurrentUsersTokens(): void
    {
        $this->authDouble->tokensResponse = [['id' => 9, 'name' => 'CLI']];
        $controller = new AuthController();

        $response = $controller->tokens(new Request([], [], ['REQUEST_URI' => '/api/v1/auth/tokens'], []));

        self::assertSame(200, $response['code']);
        self::assertSame([44], $this->authDouble->tokensCalls);
        self::assertSame(9, $response['data'][0]['id']);
    }

    public function testCurrentTokenRequiresTokenAuth(): void
    {
        $this->authDouble->sessionVia = 'session';
        $this->authDouble->tokenVia = null;
        $controller = new AuthController();

        $response = $controller->currentToken(new Request([], [], ['REQUEST_URI' => '/api/v1/auth/tokens/current'], []));

        self::assertSame(400, $response['code']);
    }

    public function testRotateCurrentTokenDelegatesNormalizedPayload(): void
    {
        $this->authDouble->sessionVia = null;
        $this->authDouble->tokenVia = 'token';
        $this->authDouble->bearerTokenValue = '9|plain-token';
        $this->authDouble->rotateTokenResponse = '10|replacement-token';
        $controller = new AuthController();

        $response = $controller->rotateCurrentToken(new Request([], [
            'token_name' => 'Rotated',
            'token_ttl' => 3600,
            'abilities' => 'reports.read, exports.read',
        ], ['REQUEST_URI' => '/api/v1/auth/tokens/rotate'], []));

        self::assertSame(200, $response['code']);
        self::assertSame('10|replacement-token', $response['token']);
        self::assertCount(1, $this->authDouble->rotateTokenCalls);
        self::assertSame('9|plain-token', $this->authDouble->rotateTokenCalls[0]['plain_token']);
        self::assertSame('Rotated', $this->authDouble->rotateTokenCalls[0]['name']);
        self::assertSame(['reports.read', 'exports.read'], $this->authDouble->rotateTokenCalls[0]['abilities']);
        self::assertIsInt($this->authDouble->rotateTokenCalls[0]['expires_at']);
    }
}