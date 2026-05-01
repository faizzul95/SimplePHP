<?php

declare(strict_types=1);

use Components\Auth;
use PHPUnit\Framework\TestCase;

final class AuthPasswordHashingProbe extends Auth
{
    public array $persisted = [];

    public function exposeMaybeRefreshPasswordHash(int $userId, string $plainPassword, string $currentHash, array $user = []): void
    {
        $this->maybeRefreshPasswordHash($userId, $plainPassword, $currentHash, $user);
    }

    public function exposePasswordHashConfiguration(): array
    {
        return $this->passwordHashConfiguration();
    }

    protected function persistPasswordRehash(int $userId, string $rehash, array $user = []): void
    {
        $this->persisted = [
            'user_id' => $userId,
            'rehash' => $rehash,
            'user' => $user,
        ];
    }
}

final class AuthPasswordHashingTest extends TestCase
{
    public function testPasswordHashConfigurationSupportsConfiguredBcryptRounds(): void
    {
        $auth = new AuthPasswordHashingProbe([
            'systems_login_policy' => [
                'password_hashing' => [
                    'enabled' => true,
                    'algorithm' => 'bcrypt',
                    'bcrypt_rounds' => 13,
                ],
            ],
        ]);

        $configuration = $auth->exposePasswordHashConfiguration();

        self::assertTrue($configuration['enabled']);
        self::assertSame(PASSWORD_BCRYPT, $configuration['algorithm']);
        self::assertSame(13, $configuration['options']['cost']);
    }

    public function testMaybeRefreshPasswordHashRehashesWhenConfiguredHashIsTooWeak(): void
    {
        $weakHash = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = new AuthPasswordHashingProbe([
            'systems_login_policy' => [
                'password_hashing' => [
                    'enabled' => true,
                    'algorithm' => 'bcrypt',
                    'bcrypt_rounds' => 12,
                ],
            ],
        ]);

        $auth->exposeMaybeRefreshPasswordHash(77, 'secret-pass', $weakHash, ['id' => 77]);

        self::assertSame(77, $auth->persisted['user_id']);
        self::assertTrue(password_verify('secret-pass', $auth->persisted['rehash']));
        self::assertTrue(password_needs_rehash($weakHash, PASSWORD_BCRYPT, ['cost' => 12]));
        self::assertFalse(password_needs_rehash($auth->persisted['rehash'], PASSWORD_BCRYPT, ['cost' => 12]));
    }

    public function testMaybeRefreshPasswordHashSkipsWhenHashingPolicyDisabled(): void
    {
        $hash = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = new AuthPasswordHashingProbe([
            'systems_login_policy' => [
                'password_hashing' => [
                    'enabled' => false,
                ],
            ],
        ]);

        $auth->exposeMaybeRefreshPasswordHash(77, 'secret-pass', $hash, ['id' => 77]);

        self::assertSame([], $auth->persisted);
    }
}