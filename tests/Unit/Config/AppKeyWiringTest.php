<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Three names existed and none connected: .env declared APP_KEY, the consumers
 * read config('app.key'), and key:generate pointed at config.php's 'app_key'.
 * Nothing assigned $config['app']['key'], so both consumers threw on every call.
 */
final class AppKeyWiringTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        $this->configFile = dirname(__DIR__, 3) . '/app/config/app.php';
    }

    public function testTheAppConfigFileExists(): void
    {
        self::assertFileExists(
            $this->configFile,
            'app/config/app.php is missing. bootstrap.php maps each config filename to a '
            . 'top-level config key, so without this file config(\'app.key\') resolves to null '
            . 'and Encryptor and SignedUrl throw on every call.'
        );
    }

    public function testItReturnsAnArrayContainingAKeyEntry(): void
    {
        $config = require $this->configFile;

        self::assertIsArray($config, 'app/config/app.php must return an array.');
        self::assertArrayHasKey('key', $config, 'app/config/app.php must define a "key" entry.');
    }

    public function testTheKeyIsSourcedFromTheAppKeyEnvironmentVariable(): void
    {
        $expected = 'test-app-key-' . bin2hex(random_bytes(8));

        $previous = getenv('APP_KEY');
        putenv('APP_KEY=' . $expected);
        $_ENV['APP_KEY'] = $expected;
        $_SERVER['APP_KEY'] = $expected;

        try {
            $config = require $this->configFile;
            self::assertSame(
                $expected,
                $config['key'],
                'app/config/app.php does not read APP_KEY from the environment.'
            );
        } finally {
            if ($previous === false) {
                putenv('APP_KEY');
                unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
            } else {
                putenv('APP_KEY=' . $previous);
                $_ENV['APP_KEY'] = $previous;
                $_SERVER['APP_KEY'] = $previous;
            }
        }
    }

    public function testItDefaultsToAnEmptyStringRatherThanNull(): void
    {
        $previous = getenv('APP_KEY');
        putenv('APP_KEY');
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

        try {
            $config = require $this->configFile;
            self::assertSame('', $config['key'], 'An unset APP_KEY should yield an empty string, not null.');
        } finally {
            if ($previous !== false) {
                putenv('APP_KEY=' . $previous);
                $_ENV['APP_KEY'] = $previous;
                $_SERVER['APP_KEY'] = $previous;
            }
        }
    }

    /**
     * bootstrap.php derives the top-level config key from the filename, so the
     * consumers' config('app.key') lookup depends on this file being named app.php.
     */
    public function testTheFilenameMatchesTheConfigKeyItsConsumersRead(): void
    {
        self::assertSame('app', pathinfo($this->configFile, PATHINFO_FILENAME));

        foreach ([
            'systems/Core/Security/Encryptor.php',
            'systems/Core/Security/SignedUrl.php',
        ] as $relative) {
            $path = dirname(__DIR__, 3) . '/' . $relative;
            self::assertStringContainsString(
                "config('app.key')",
                (string) file_get_contents($path),
                $relative . ' no longer reads config(\'app.key\') — update this test or the config file.'
            );
        }
    }

    public function testKeyGenerateWritesToEnvRatherThanPrintingInstructions(): void
    {
        $commands = (string) file_get_contents(dirname(__DIR__, 3) . '/systems/Core/Console/Commands.php');

        $start = strpos($commands, "command('key:generate'");
        self::assertNotFalse($start, 'The key:generate command is missing.');

        $body = substr($commands, $start, 3000);

        self::assertStringContainsString(
            'writeEnvValue',
            $body,
            'key:generate must persist the key with writeEnvValue(). Printing it and asking the '
            . 'developer to paste it somewhere is how APP_KEY ended up unset in the first place.'
        );
        self::assertStringNotContainsString(
            "config/config.php as 'app_key'",
            $body,
            'key:generate still points at a config location nothing reads.'
        );
    }
}
