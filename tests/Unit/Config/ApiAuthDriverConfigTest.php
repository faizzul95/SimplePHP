<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped configuration is what a fresh clone gets, so it is worth asserting
 * on directly rather than only through a hand-built fixture.
 *
 * The claim under test: clone the repository, write no .env, and the application
 * API authenticates with bearer tokens — no session, no CSRF token to fetch
 * first. One value moves it back to cookies.
 */
final class ApiAuthDriverConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = ['env' => $_ENV, 'server' => $_SERVER];
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup['env'];
        $_SERVER = $this->envBackup['server'];
        parent::tearDown();
    }

    /**
     * Load the real config files the way bootstrap.php does, with $config carried
     * from one to the next so framework.php can see what api.php resolved.
     *
     * @return array<string, mixed>
     */
    private function loadShippedConfig(?string $driver): array
    {
        unset($_ENV['API_AUTH_DRIVER'], $_SERVER['API_AUTH_DRIVER']);

        if ($driver !== null) {
            $_ENV['API_AUTH_DRIVER'] = $driver;
        }

        $config = [];

        // api.php sorts before framework.php, which is the order bootstrap relies on.
        require ROOT_DIR . 'app/config/api.php';
        require ROOT_DIR . 'app/config/framework.php';

        return $config;
    }

    /** @return list<string> */
    private function appApiStack(?string $driver): array
    {
        $config = $this->loadShippedConfig($driver);

        return (array) $config['framework']['middleware_groups']['api.app'];
    }

    // ─── The default ─────────────────────────────────────────────────

    public function testAFreshCloneAuthenticatesTheApiWithTokens(): void
    {
        $config = $this->loadShippedConfig(null);

        self::assertSame('token', $config['api']['driver']);
    }

    public function testTheDefaultStackStartsNoSessionAndAsksForNoCsrfToken(): void
    {
        $stack = $this->appApiStack(null);

        self::assertContains('auth.api', $stack);
        self::assertNotContains('session.stateful:force', $stack);

        foreach ($stack as $layer) {
            self::assertStringStartsNotWith('csrf', $layer, 'A token client has no CSRF token to send.');
        }
    }

    /**
     * A mobile client has no Origin header. `origin.policy:strict` rejects a
     * write with neither Origin nor Referer, which would 403 every POST from an
     * app — so the token stack must not carry it.
     */
    public function testTheDefaultStackDoesNotDemandAnOriginHeader(): void
    {
        self::assertNotContains('origin.policy:strict', $this->appApiStack(null));
    }

    // ─── Opting back into cookies ────────────────────────────────────

    public function testSessionDriverRestoresTheCookieStack(): void
    {
        self::assertSame(
            ['api', 'origin.policy:strict', 'session.stateful:force', 'auth.web', 'csrf:force'],
            $this->appApiStack('session')
        );
    }

    public function testHybridAcceptsEitherCredentialAndGatesCsrfOnTheStatefulOne(): void
    {
        $stack = $this->appApiStack('hybrid');

        self::assertContains('auth:token,session', $stack);
        self::assertContains('csrf:stateful', $stack);
        self::assertContains('session.stateful:force', $stack);
    }

    /**
     * CSRF has to run after authentication in hybrid mode: the skip is decided by
     * which credential actually succeeded, and that is not known until the auth
     * middleware has run.
     */
    public function testHybridValidatesCsrfAfterAuthenticating(): void
    {
        $stack = $this->appApiStack('hybrid');

        self::assertLessThan(
            array_search('csrf:stateful', $stack, true),
            array_search('auth:token,session', $stack, true)
        );
    }

    // ─── Bad input ───────────────────────────────────────────────────

    /** @return list<array{0:string}> */
    public static function invalidDriverProvider(): array
    {
        return [['jwt'], ['cookie'], [''], ['TOKEN; DROP']];
    }

    /**
     * A typo in .env must not silently disable authentication or fall through to
     * an empty stack. Anything unrecognised lands on the secure default.
     */
    #[DataProvider('invalidDriverProvider')]
    public function testAnUnrecognisedDriverFallsBackToToken(string $driver): void
    {
        $config = $this->loadShippedConfig($driver);

        self::assertSame('token', $config['api']['driver']);
        self::assertContains('auth.api', (array) $config['framework']['middleware_groups']['api.app']);
    }

    public function testTheDriverIsCaseInsensitive(): void
    {
        self::assertSame('session', $this->loadShippedConfig('SESSION')['api']['driver']);
        self::assertSame('hybrid', $this->loadShippedConfig(' Hybrid ')['api']['driver']);
    }

    // ─── The external API is unaffected ──────────────────────────────

    /**
     * `api.external.auth` is the machine-to-machine surface. Switching the app's
     * own API to cookies must not drag the external API along with it.
     */
    #[DataProvider('everyDriverProvider')]
    public function testTheExternalApiStaysCredentialBased(string $driver): void
    {
        $config = $this->loadShippedConfig($driver);

        self::assertSame(
            ['api', 'auth.api'],
            (array) $config['framework']['middleware_groups']['api.external.auth']
        );
    }

    /** @return list<array{0:string}> */
    public static function everyDriverProvider(): array
    {
        return [['token'], ['session'], ['hybrid']];
    }

    /**
     * Both upload groups compose on top of api.app, so whatever the driver
     * resolves to has to reach them too.
     */
    #[DataProvider('everyDriverProvider')]
    public function testUploadGroupsInheritTheDriver(string $driver): void
    {
        $groups = $this->loadShippedConfig($driver)['framework']['middleware_groups'];

        self::assertSame('api.app', $groups['api.upload.image'][0]);
        self::assertSame('api.app', $groups['api.upload.action'][0]);
    }
}
