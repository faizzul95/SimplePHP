<?php

declare(strict_types=1);

require_once ROOT_DIR . 'app/support/SecurityAuditRunner.php';

use App\Support\SecurityAuditRunner;
use PHPUnit\Framework\TestCase;

final class SecurityAuditRunnerTest extends TestCase
{
    public function testRunFlagsRiskySecurityConfiguration(): void
    {
        $runner = new SecurityAuditRunner();
        $report = $runner->run('production', [
            'csrf' => [
                'csrf_protection' => false,
                'csrf_secure_cookie' => false,
                'csrf_origin_check' => false,
            ],
            'request_hardening' => [
                'enabled' => false,
            ],
            'trusted' => [
                'hosts' => [],
            ],
            'csp' => [
                'enabled' => false,
                'script-src' => ["'self'", "'unsafe-inline'"],
            ],
            'headers' => [
                'hsts' => ['enabled' => false],
                'x_content_type_options' => 'off',
            ],
            'permissions_policy' => [],
        ], [
            'cors' => [
                'allow_origin' => ['*'],
                'allow_credentials' => true,
            ],
            'auth' => [
                'required' => false,
                'methods' => [],
            ],
            'rate_limit' => [
                'enabled' => false,
            ],
        ]);

        self::assertGreaterThan(0, $report['summary']['fail']);
        self::assertSame('fail', $this->statusFor($report['checks'], 'csrf.enabled'));
        self::assertSame('fail', $this->statusFor($report['checks'], 'api.cors.origins'));
        self::assertSame('warn', $this->statusFor($report['checks'], 'permissions_policy.configured'));
    }

    public function testRunAuditsResponseHeadersAndCookieFlags(): void
    {
        $runner = new SecurityAuditRunner(static function (array $request): array {
            self::assertSame('GET', $request['method']);
            self::assertSame('https://app.example.test/login', $request['url']);

            return [
                'status' => 200,
                'headers' => [
                    'server' => 'Apache/2.4.58',
                    'set-cookie' => 'resi_session=abc123; Path=/',
                    'x-content-type-options' => 'nosniff',
                ],
            ];
        });

        $report = $runner->run('production', [
            'csrf' => [
                'csrf_protection' => true,
                'csrf_secure_cookie' => true,
                'csrf_origin_check' => true,
            ],
            'request_hardening' => ['enabled' => true],
            'trusted' => ['hosts' => ['app.example.test']],
            'csp' => [
                'enabled' => true,
                'script-src' => ["'self'"],
            ],
            'headers' => [
                'hsts' => ['enabled' => true],
                'x_content_type_options' => 'nosniff',
            ],
            'permissions_policy' => ['geolocation' => '()'],
        ], [
            'cors' => ['allow_origin' => ['https://app.example.test']],
            'auth' => [
                'required' => true,
                'methods' => ['token'],
            ],
            'rate_limit' => ['enabled' => true],
        ], 'https://app.example.test/login');

        self::assertSame('pass', $this->statusFor($report['checks'], 'target.reachable'));
        self::assertSame('warn', $this->statusFor($report['checks'], 'header.csp'));
        self::assertSame('warn', $this->statusFor($report['checks'], 'cookie.1.httponly'));
        self::assertSame('warn', $this->statusFor($report['checks'], 'cookie.1.secure'));
        self::assertSame('warn', $this->statusFor($report['checks'], 'header.server_banner'));
    }

    public function testRunUsesAuthenticatedSessionProbeBeforeAuditingProtectedTarget(): void
    {
        $requests = [];

        $runner = new SecurityAuditRunner(static function (array $request) use (&$requests): array {
            $requests[] = $request;

            if ($request['method'] === 'GET' && $request['url'] === 'https://app.example.test/login') {
                return [
                    'status' => 200,
                    'headers' => [
                        'set-cookie' => [
                            'csrf_cookie=csrf-cookie-value; Path=/; HttpOnly',
                        ],
                    ],
                    'body' => '<html><head><meta name="csrf-token" content="csrf-token-value"></head></html>',
                ];
            }

            if ($request['method'] === 'POST' && $request['url'] === 'https://app.example.test/auth/login') {
                self::assertStringContainsString('username=auditor', (string) $request['body']);
                self::assertStringContainsString('password=secret', (string) $request['body']);
                self::assertStringContainsString('csrf_token=csrf-token-value', (string) $request['body']);
                self::assertSame('csrf_cookie=csrf-cookie-value', $request['headers']['Cookie']);

                return [
                    'status' => 200,
                    'headers' => [
                        'set-cookie' => [
                            'resi_session=session-token; Path=/; HttpOnly; Secure; SameSite=Lax',
                        ],
                    ],
                    'body' => '{"code":200,"message":"Login","redirectUrl":"https://app.example.test/dashboard"}',
                ];
            }

            if ($request['method'] === 'GET' && $request['url'] === 'https://app.example.test/dashboard') {
                self::assertStringContainsString('resi_session=session-token', $request['headers']['Cookie']);

                return [
                    'status' => 200,
                    'headers' => [
                        'content-security-policy' => "default-src 'self'",
                        'x-content-type-options' => 'nosniff',
                        'referrer-policy' => 'strict-origin-when-cross-origin',
                        'permissions-policy' => 'geolocation=()',
                        'strict-transport-security' => 'max-age=31536000; includeSubDomains; preload',
                    ],
                    'body' => '<html>dashboard</html>',
                ];
            }

            self::fail('Unexpected request: ' . json_encode($request, JSON_UNESCAPED_SLASHES));
        });

        $report = $runner->run('production', [
            'csrf' => [
                'csrf_protection' => true,
                'csrf_secure_cookie' => true,
                'csrf_origin_check' => true,
                'csrf_token_name' => 'csrf_token',
            ],
            'request_hardening' => ['enabled' => true],
            'trusted' => ['hosts' => ['app.example.test']],
            'csp' => [
                'enabled' => true,
                'script-src' => ["'self'"],
            ],
            'headers' => [
                'hsts' => ['enabled' => true],
                'x_content_type_options' => 'nosniff',
            ],
            'permissions_policy' => ['geolocation' => '()'],
        ], [
            'cors' => ['allow_origin' => ['https://app.example.test']],
            'auth' => [
                'required' => true,
                'methods' => ['token'],
            ],
            'rate_limit' => ['enabled' => true],
        ], 'https://app.example.test/dashboard', 5, [
            'auth_mode' => 'session',
            'username' => 'auditor',
            'password' => 'secret',
        ]);

        self::assertCount(3, $requests);
        self::assertSame('pass', $this->statusFor($report['checks'], 'auth.session.login_page'));
        self::assertSame('pass', $this->statusFor($report['checks'], 'auth.session.csrf_token'));
        self::assertSame('pass', $this->statusFor($report['checks'], 'auth.session.login_success'));
        self::assertSame('pass', $this->statusFor($report['checks'], 'auth.session.target_access'));
        self::assertSame('pass', $this->statusFor($report['checks'], 'header.hsts'));
    }

    /**
     * @param array<int, array<string, string>> $checks
     */
    private function statusFor(array $checks, string $id): string
    {
        foreach ($checks as $check) {
            if (($check['id'] ?? null) === $id) {
                return (string) $check['status'];
            }
        }

        self::fail('Unable to find check: ' . $id);
    }
}