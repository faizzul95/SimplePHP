---
name: myth-testing
description: >
  Testing guide for the MythPHP framework using PHPUnit. Use when: writing unit
  tests, integration tests, or middleware tests; setting up test bootstrap and config
  stubs; testing FormRequests, controllers, middleware, auth, database queries, jobs,
  console commands, cache, or security components; running the test suite; creating
  test doubles or fixtures for framework services.
argument-hint: 'Component to test, e.g. "middleware", "FormRequest", "auth", "controller"'
---

# MythPHP Testing Guide

## When to Use This Skill

Load this skill when writing or debugging PHPUnit tests for the MythPHP framework.
The test infrastructure is purpose-built — use the patterns shown here rather than
assuming standard Laravel test helpers exist.

---

## Running Tests

```bash
# Run all unit tests
vendor/bin/phpunit

# Run a specific suite
vendor/bin/phpunit --testsuite Unit

# Run a single file
vendor/bin/phpunit tests/Unit/Http/ValidateRequestSafetyTest.php

# Run a specific test method
vendor/bin/phpunit --filter testRejectsOversizedBody

# Run with coverage (requires Xdebug or PCOV)
vendor/bin/phpunit --coverage-text
```

Config: `phpunit.xml.dist` — bootstrap: `tests/bootstrap.php`.

---

## Test Directory Structure

```
tests/
├── bootstrap.php             # Framework bootstrap for tests
├── Unit/
│   ├── Auth/                 # Auth component tests
│   ├── Components/           # Component-level unit tests
│   ├── Console/              # Console command tests
│   ├── Database/             # Query builder, schema, migrations
│   ├── Diagnostics/          # Debug/profiling tests
│   ├── Http/                 # Middleware + HTTP layer tests
│   ├── Routing/              # Router tests
│   ├── Security/             # Security component tests
│   ├── Support/              # Collection, helpers, utilities
│   └── View/                 # Blade engine tests
├── Integration/
│   ├── Api/                  # Full API request cycle tests
│   ├── Auth/                 # Auth flow integration
│   ├── Bootstrap/            # Service provider + bootstrap tests
│   ├── Database/             # Real DB integration tests
│   ├── Http/                 # HTTP integration tests
│   └── Middleware/           # Middleware pipeline integration
├── Doubles/                  # Test doubles (stubs, fakes, mocks)
├── Fixtures/                 # Static data files for tests
├── Helpers/                  # Test helper utilities
└── Support/                  # Shared test base classes
```

---

## Test Bootstrap (`tests/bootstrap.php`)

The bootstrap defines `ROOT_DIR`, loads `vendor/autoload.php` and `systems/hooks.php`,
and provides `bootstrapTestFrameworkServices()` for wiring service providers in tests.

### Bootstrap framework services in a test

```php
use PHPUnit\Framework\TestCase;

final class MyFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot framework services with custom config stubs
        bootstrapTestFrameworkServices([
            'database' => [
                'default'     => 'mysql',
                'connections' => [
                    'mysql' => [
                        'host'     => '127.0.0.1',
                        'port'     => 3306,
                        'database' => 'mythphp_test',
                        'username' => 'root',
                        'password' => '',
                    ],
                ],
            ],
            'cache' => ['default' => 'array'],  // Use in-memory cache for tests
        ]);
    }
}
```

---

## Middleware Test Pattern

Middleware tests inject `$_SERVER` / `$_GET` / `$_POST` / `$_FILES` directly and use
anonymous subclasses to capture the `reject()` response instead of real HTTP output.

```php
use App\Http\Middleware\ValidateRequestSafety;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidateRequestSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset superglobals before every test
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/api/test',
            'SCRIPT_NAME'    => '/index.php',
            'HTTP_HOST'      => 'localhost',
            'CONTENT_TYPE'   => 'application/json',
            'REMOTE_ADDR'    => '127.0.0.1',
        ];

        // Seed config stubs
        $GLOBALS['config']['security']['request_hardening'] = [
            'enabled'      => true,
            'max_uri_length' => 2000,
            'max_body_bytes' => 1048576,
        ];
    }

    public function testRejectsOversizedUri(): void
    {
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('a', 3000);

        $middleware = new class extends ValidateRequestSafety {
            public ?int $rejectedStatus = null;
            protected function reject(Request $request, int $status, string $message): void
            {
                $this->rejectedStatus = $status;
            }
        };

        $request = Request::capture();
        $called = false;
        $middleware->handle($request, function () use (&$called) { $called = true; });

        $this->assertSame(414, $middleware->rejectedStatus);
        $this->assertFalse($called);
    }
}
```

---

## FormRequest Test Pattern

Test FormRequest validation by directly instantiating and calling `validateResolved()`.

```php
use Core\Http\FormRequest;
use PHPUnit\Framework\TestCase;

final class SaveUserRequestTest extends TestCase
{
    private function makeRequest(array $input): SaveUserRequest
    {
        $_POST = $input;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = new SaveUserRequest();
        $request->setRequest(\Core\Http\Request::capture());
        return $request;
    }

    public function testPassesWithValidInput(): void
    {
        $req = $this->makeRequest(['name' => 'Alice', 'email' => 'alice@example.com']);
        $req->validateResolved();
        $this->assertSame('alice@example.com', $req->validated('email'));
    }

    public function testFailsWithMissingEmail(): void
    {
        $this->expectException(\Core\Http\Exceptions\ValidationException::class);
        $req = $this->makeRequest(['name' => 'Alice']);
        $req->validateResolved();
    }

    public function testSanitizesName(): void
    {
        $req = $this->makeRequest(['name' => '  <b>Alice</b>  ', 'email' => 'alice@example.com']);
        $req->validateResolved();
        $this->assertSame('Alice', $req->validated('name'));  // strip_tags + trim
    }
}
```

---

## Database Query Builder Test Pattern

Use `bootstrapTestFrameworkServices()` with a real test database, or the `array` driver
for in-memory query inspection.

```php
final class UserQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bootstrapTestFrameworkServices(['cache' => ['default' => 'array']]);
        // Seed test data using fixtures or Schema + insert
    }

    public function testFiltersActiveUsers(): void
    {
        $users = db()->table('users')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->get();

        $this->assertNotEmpty($users);
        foreach ($users as $user) {
            $this->assertSame('active', $user['status']);
        }
    }

    public function testDebugSqlContainsWhereClause(): void
    {
        $sql = db()->table('users')->where('status', 'active')->toRawSql();
        $this->assertStringContainsString("status = 'active'", $sql);
    }
}
```

---

## Auth Test Pattern

```php
final class AuthSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bootstrapTestFrameworkServices();

        // Stub session globals
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testCheckReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(auth()->checkSession());
    }

    public function testLoginSetsSessionKeys(): void
    {
        auth()->login(1, ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com']);
        $this->assertTrue(auth()->checkSession());
        $this->assertSame(1, auth()->id());
    }
}
```

---

## Security Component Test Pattern

```php
use Components\Security;
use PHPUnit\Framework\TestCase;

final class SecurityComponentTest extends TestCase
{
    private Security $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = new Security();
    }

    public function testBlocksSqlInjection(): void
    {
        $issues = $this->security->containsSqlInjection("' OR 1=1 --");
        $this->assertNotEmpty($issues);
    }

    public function testBlocksXss(): void
    {
        $this->assertTrue($this->security->containsXss('<script>alert(1)</script>'));
    }

    public function testAllowsSafeInput(): void
    {
        $issues = $this->security->containsMalicious('Hello World');
        $this->assertEmpty($issues);
    }

    public function testBlocksPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->security->normalizeRelativeProjectPath('../etc/passwd');
    }
}
```

---

## Cache Test Pattern

Use the `array` store (in-memory, no filesystem) for unit tests:

```php
protected function setUp(): void
{
    parent::setUp();
    bootstrapTestFrameworkServices(['cache' => ['default' => 'array']]);
}

public function testRememberCallsCallbackOnMiss(): void
{
    $calls = 0;
    $result = cache()->remember('test.key', 60, function () use (&$calls) {
        $calls++;
        return 'computed';
    });

    $this->assertSame('computed', $result);
    $this->assertSame(1, $calls);

    // Second call — should NOT re-execute callback
    cache()->remember('test.key', 60, function () use (&$calls) {
        $calls++;
        return 'computed';
    });
    $this->assertSame(1, $calls);  // Still 1
}
```

---

## Console Command Test Pattern

```php
use PHPUnit\Framework\TestCase;

final class CacheClearCommandTest extends TestCase
{
    public function testCacheClearExitsZero(): void
    {
        // Run via process (safest for commands that touch filesystem)
        $output = shell_exec('php myth cache:clear 2>&1');
        $this->assertStringContainsString('cleared', strtolower($output));
    }
}
```

---

## Config Stub Pattern

Seed `$GLOBALS['config']` directly in `setUp()` to override framework config for a single test:

```php
protected function setUp(): void
{
    parent::setUp();
    $GLOBALS['config']['security']['request_hardening']['enabled'] = true;
    $GLOBALS['config']['security']['request_hardening']['max_uri_length'] = 500;
}
```

---

## Test Checklist

- [ ] Use `bootstrapTestFrameworkServices()` when the test requires a service singleton.
- [ ] Reset `$_GET`, `$_POST`, `$_FILES`, `$_SERVER` in `setUp()` for HTTP tests.
- [ ] Use `cache.default = array` to avoid filesystem side-effects.
- [ ] Use `toRawSql()` / `toDebugSql()` to verify query structure without a real database.
- [ ] Use anonymous subclass pattern to capture middleware `reject()` calls.
- [ ] Mark tests `final` by convention (prevents accidental inheritance).
- [ ] Run `vendor/bin/phpunit` before committing — zero tolerance for regressions.
