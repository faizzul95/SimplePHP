<?php

declare(strict_types=1);

use App\Support\Auth\AuthManager;
use PHPUnit\Framework\TestCase;

final class AuthManagerProbe extends AuthManager
{
    public array $received = [];

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public function check(array|string|null $methods = null): bool
    {
        $this->received['check'] = $methods;

        return $methods === ['session'] || $methods === ['token'];
    }

    public function user(array|string|null $methods = null): ?array
    {
        $this->received['user'] = $methods;

        return ['id' => 7, 'roles' => ['admin'], 'abilities' => ['users.read']];
    }

    public function via(array|string|null $methods = null): ?string
    {
        $this->received['via'] = $methods;

        return is_array($methods) ? ($methods[0] ?? null) : null;
    }
}

final class AuthManagerGuardTest extends TestCase
{
    public function testManagerResolvesDefaultAndNamedGuards(): void
    {
        $manager = new AuthManagerProbe([
            'methods' => ['session'],
            'api_methods' => ['token'],
        ]);

        self::assertTrue($manager->guard('web')->check());
        self::assertSame(['session'], $manager->received['check']);
        self::assertTrue($manager->guard('api')->check());
        self::assertSame(['token'], $manager->received['check']);
        self::assertSame('session', $manager->guard('web')->via());
        self::assertSame(7, $manager->guard('web')->user()['id']);
    }
}