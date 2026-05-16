<?php

declare(strict_types=1);

use Core\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class SecurityAuditCommandTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', 'production');
        }

        bootstrapTestFrameworkServices([
            'security' => [
                'csrf' => [
                    'csrf_protection' => true,
                    'csrf_secure_cookie' => true,
                    'csrf_origin_check' => true,
                ],
                'request_hardening' => [
                    'enabled' => true,
                ],
                'trusted' => [
                    'hosts' => ['app.example.test'],
                ],
                'csp' => [
                    'enabled' => true,
                    'script-src' => ["'self'"],
                ],
                'headers' => [
                    'hsts' => ['enabled' => true],
                    'x_content_type_options' => 'nosniff',
                ],
                'permissions_policy' => [
                    'geolocation' => '()'
                ],
                'query_allowlist' => [
                    'enabled' => false,
                ],
            ],
            'api' => [
                'cors' => [
                    'allow_origin' => ['https://app.example.test'],
                    'allow_credentials' => false,
                ],
                'auth' => [
                    'required' => true,
                    'methods' => ['token'],
                ],
                'rate_limit' => [
                    'enabled' => true,
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function testSecurityAuditCommandPrintsSuccessfulJsonSummary(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"summary"', $kernel->output());
        self::assertStringContainsString('"fail": 0', $kernel->output());
        self::assertStringContainsString('"target": null', $kernel->output());
    }

    public function testSecurityAuditCommandFailsFastWhenSessionAuthCredentialsAreMissing(): void
    {
        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
            'url' => 'https://app.example.test/dashboard',
            'auth' => 'session',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"auth": "session"', $kernel->output());
        self::assertStringContainsString('auth.session.credentials', $kernel->output());
    }

    public function testSecurityAuditCommandFailsQueryAllowlistCheckWhenUnsafeDynamicOrderByExists(): void
    {
        [$controllersDir, $modelsDir] = $this->createQueryAllowlistFixtureDirectories();

        file_put_contents($controllersDir . DIRECTORY_SEPARATOR . 'UnsafeController.php', <<<'PHP'
<?php

class UnsafeController
{
    public function index($request)
    {
        return db()->table('users')->orderBy($request->input('sort'), 'asc')->get();
    }
}
PHP);

        bootstrapTestFrameworkServices([
            'security' => [
                'query_allowlist' => [
                    'enabled' => true,
                    'controller_paths' => [$controllersDir],
                    'model_paths' => [$modelsDir],
                ],
            ],
        ]);

        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
            'check' => 'query-allowlist',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('query_allowlist.dynamic_order_by', $kernel->output());
    }

    public function testSecurityAuditCommandPassesQueryAllowlistCheckForSafeFixtures(): void
    {
        [$controllersDir, $modelsDir] = $this->createQueryAllowlistFixtureDirectories();

        file_put_contents($controllersDir . DIRECTORY_SEPARATOR . 'SafeController.php', <<<'PHP'
<?php

class SafeController
{
    public function index($request)
    {
        return db()->table('users')
            ->setSortableColumns(['users.name'])
            ->orderBySafe($request->input('sort'), $request->input('dir'))
            ->setFilterableColumns(['users.email'])
            ->whereSafe('email', 'a@example.test')
            ->get();
    }
}
PHP);

        file_put_contents($modelsDir . DIRECTORY_SEPARATOR . 'SafeUser.php', <<<'PHP'
<?php

use Core\Database\Model;

class SafeUser extends Model
{
    protected array $sortable = ['users.name'];
    protected array $filterable = ['users.email'];
}
PHP);

        bootstrapTestFrameworkServices([
            'security' => [
                'query_allowlist' => [
                    'enabled' => true,
                    'controller_paths' => [$controllersDir],
                    'model_paths' => [$modelsDir],
                ],
            ],
        ]);

        $kernel = new Kernel();
        $exitCode = $kernel->callSilently('security:audit', [
            'format' => 'json',
            'check' => 'query-allowlist',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('query_allowlist.model_metadata', $kernel->output());
        self::assertStringContainsString('"fail": 0', $kernel->output());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createQueryAllowlistFixtureDirectories(): array
    {
        $base = ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'testing' . DIRECTORY_SEPARATOR . 'query-allowlist-' . uniqid('', true);
        $controllersDir = $base . DIRECTORY_SEPARATOR . 'controllers';
        $modelsDir = $base . DIRECTORY_SEPARATOR . 'models';

        mkdir($controllersDir, 0777, true);
        mkdir($modelsDir, 0777, true);

        $this->tempDirectories[] = $base;

        return [$controllersDir, $modelsDir];
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}