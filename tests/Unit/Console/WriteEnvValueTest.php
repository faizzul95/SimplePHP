<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Core\Console\Commands;
use PHPUnit\Framework\TestCase;

/**
 * writeEnvValue() is what lets key:generate persist APP_KEY. A .env is hand
 * maintained, so comments, ordering, blank lines and line endings must survive.
 */
final class WriteEnvValueTest extends TestCase
{
    private string $dir;
    private string $envFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mythphp_env_test_' . uniqid();
        mkdir($this->dir, 0750, true);
        $this->envFile = $this->dir . '/.env';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/.*') ?: [] as $file) {
            if (!is_dir($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->envFile, $contents);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->envFile);
    }

    public function testReplacesAnExistingKeyInPlace(): void
    {
        $this->write("APP_ENV=development\nAPP_KEY=old-value\nDB_HOST=localhost\n");

        self::assertTrue(Commands::writeEnvValue('APP_KEY', 'new-value', $this->envFile));
        self::assertSame("APP_ENV=development\nAPP_KEY=new-value\nDB_HOST=localhost\n", $this->read());
    }

    public function testAppendsWhenTheKeyIsAbsent(): void
    {
        $this->write("APP_ENV=development\n");

        self::assertTrue(Commands::writeEnvValue('APP_KEY', 'abc123', $this->envFile));
        self::assertSame("APP_ENV=development\nAPP_KEY=abc123\n", $this->read());
    }

    public function testPreservesCommentsBlankLinesAndOrdering(): void
    {
        $original = "# Application\nAPP_ENV=development\n\n# Database\nDB_HOST=localhost\nDB_PORT=3306\n";
        $this->write($original);

        Commands::writeEnvValue('DB_HOST', '127.0.0.1', $this->envFile);

        self::assertSame(
            "# Application\nAPP_ENV=development\n\n# Database\nDB_HOST=127.0.0.1\nDB_PORT=3306\n",
            $this->read()
        );
    }

    public function testPreservesCrlfLineEndings(): void
    {
        $this->write("APP_ENV=development\r\nAPP_KEY=old\r\n");

        Commands::writeEnvValue('APP_KEY', 'new', $this->envFile);

        self::assertSame("APP_ENV=development\r\nAPP_KEY=new\r\n", $this->read());
        self::assertStringNotContainsString("\n\n", str_replace("\r\n", "\n", $this->read()));
    }

    public function testAppendsUsingTheFilesExistingCrlfEnding(): void
    {
        $this->write("APP_ENV=development\r\n");

        Commands::writeEnvValue('APP_KEY', 'abc', $this->envFile);

        self::assertSame("APP_ENV=development\r\nAPP_KEY=abc\r\n", $this->read());
    }

    public function testDoesNotUncommentADisabledKey(): void
    {
        // A commented key is a deliberate choice; the writer must append instead.
        $this->write("# APP_KEY=disabled-on-purpose\nAPP_ENV=development\n");

        Commands::writeEnvValue('APP_KEY', 'fresh', $this->envFile);

        $result = $this->read();
        self::assertStringContainsString('# APP_KEY=disabled-on-purpose', $result);
        self::assertStringContainsString("APP_KEY=fresh", $result);
    }

    public function testDoesNotMatchAKeyThatMerelySharesAPrefix(): void
    {
        $this->write("APP_KEYSTORE=/path/to/store\nAPP_ENV=development\n");

        Commands::writeEnvValue('APP_KEY', 'real-key', $this->envFile);

        $result = $this->read();
        self::assertStringContainsString('APP_KEYSTORE=/path/to/store', $result);
        self::assertStringContainsString("APP_KEY=real-key\n", $result);
    }

    public function testQuotesValuesThatNeedIt(): void
    {
        $this->write("APP_ENV=development\n");

        Commands::writeEnvValue('MAIL_FROM_NAME', 'My App Name', $this->envFile);

        self::assertStringContainsString('MAIL_FROM_NAME="My App Name"', $this->read());
    }

    public function testEscapesQuotesAndBackslashesInsideAQuotedValue(): void
    {
        $this->write("APP_ENV=development\n");

        Commands::writeEnvValue('WEIRD', 'a "quoted" \\ value', $this->envFile);

        self::assertStringContainsString('WEIRD="a \"quoted\" \\\\ value"', $this->read());
    }

    public function testDoesNotQuoteASimpleHexKey(): void
    {
        $this->write("APP_KEY=\n");
        $hex = bin2hex(random_bytes(32));

        Commands::writeEnvValue('APP_KEY', $hex, $this->envFile);

        self::assertStringContainsString("APP_KEY={$hex}", $this->read());
        self::assertStringNotContainsString('"', $this->read());
    }

    public function testHandlesAFileWithNoTrailingNewline(): void
    {
        $this->write('APP_ENV=development');

        Commands::writeEnvValue('APP_KEY', 'abc', $this->envFile);

        self::assertSame("APP_ENV=development\nAPP_KEY=abc\n", $this->read());
    }

    public function testTreatsADollarSignInTheValueLiterally(): void
    {
        // preg_replace would otherwise interpret $1 as a backreference.
        $this->write("APP_KEY=old\n");

        Commands::writeEnvValue('APP_KEY', 'pa$1ss$2word', $this->envFile);

        self::assertStringContainsString('pa$1ss$2word', $this->read());
    }

    /**
     * A quoted value containing a backslash exercises the replacement-escaping rules,
     * which the append path never touches. Both paths must agree.
     */
    public function testReplacingAndAppendingProduceTheSameLine(): void
    {
        $value = 'C:\\srv my app';

        $this->write("PATHY=old\n");
        Commands::writeEnvValue('PATHY', $value, $this->envFile);
        $replaced = trim($this->read());

        $this->write("OTHER=x\n");
        Commands::writeEnvValue('PATHY', $value, $this->envFile);
        $appended = trim(explode("\n", $this->read())[1]);

        self::assertSame($appended, $replaced, 'The replace path mangled the value.');
        self::assertStringContainsString('C:\\\\srv my app', $replaced);
    }

    public function testRejectsAnInvalidKeyName(): void
    {
        $this->write("APP_ENV=development\n");

        self::assertFalse(Commands::writeEnvValue('', 'x', $this->envFile));
        self::assertFalse(Commands::writeEnvValue('1BAD', 'x', $this->envFile));
        self::assertFalse(Commands::writeEnvValue('BAD KEY', 'x', $this->envFile));
        self::assertFalse(Commands::writeEnvValue('BAD-KEY', 'x', $this->envFile));

        self::assertSame("APP_ENV=development\n", $this->read(), 'A rejected key must not modify the file.');
    }

    public function testReturnsFalseWhenTheFileDoesNotExist(): void
    {
        self::assertFalse(Commands::writeEnvValue('APP_KEY', 'x', $this->dir . '/.env.missing'));
    }

    public function testLeavesNoTemporaryFilesBehind(): void
    {
        $this->write("APP_KEY=old\n");

        Commands::writeEnvValue('APP_KEY', 'new', $this->envFile);

        self::assertSame([], glob($this->dir . '/*.tmp') ?: [], 'Atomic write left a .tmp file behind.');
    }

    public function testOnlyReplacesTheFirstOccurrenceOfADuplicatedKey(): void
    {
        // A duplicated key is user error, but the writer must not corrupt the file.
        $this->write("APP_KEY=first\nAPP_ENV=development\nAPP_KEY=second\n");

        Commands::writeEnvValue('APP_KEY', 'winner', $this->envFile);

        $result = $this->read();
        self::assertStringContainsString('APP_KEY=winner', $result);
        self::assertSame(1, substr_count($result, 'APP_KEY=winner'));
    }
}
