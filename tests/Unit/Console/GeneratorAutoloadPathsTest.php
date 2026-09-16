<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Four generators wrote to lowercase directories while declaring capitalised
 * namespaces, so generated classes autoloaded on NTFS and failed on Linux.
 * Resolves through the real composer.json map rather than hardcoding directories.
 */
final class GeneratorAutoloadPathsTest extends TestCase
{
    private string $root;

    /** @var array<string,string> */
    private array $psr4;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);

        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertIsArray($composer);

        $this->psr4 = $composer['autoload']['psr-4'] ?? [];
        self::assertNotEmpty($this->psr4, 'composer.json has no psr-4 autoload map.');
    }

    /**
     * Resolve a class name to the directory PSR-4 would look in, using the
     * longest matching namespace prefix (which is how composer resolves).
     */
    private function resolveDirectory(string $class): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($class, $prefix) && strlen($prefix) > $bestLength) {
                $bestLength = strlen($prefix);
                $remainder = substr($class, strlen($prefix));
                $sub = str_contains($remainder, '\\')
                    ? str_replace('\\', '/', substr($remainder, 0, strrpos($remainder, '\\'))) . '/'
                    : '';
                $best = rtrim((string) $dir, '/') . '/' . $sub;
            }
        }

        return $best;
    }

    /**
     * @return array<string, array{0:string, 1:string}> generator => [namespace, output dir]
     */
    public static function generatorProvider(): array
    {
        return [
            'make:controller' => ['App\Http\Controllers\ExampleController', 'app/http/controllers/'],
            'make:middleware' => ['App\Http\Middleware\ExampleMiddleware',  'app/http/middleware/'],
            'make:request'    => ['App\Http\Requests\ExampleRequest',       'app/http/requests/'],
            'make:model'      => ['App\Models\Example',                     'app/models/'],
            'make:job'        => ['App\Jobs\ExampleJob',                    'app/jobs/'],
            'make:command'    => ['App\Console\Commands\ExampleCommand',    'app/console/commands/'],
            'make:repository' => ['App\Repositories\ExampleRepository',     'app/repositories/'],
            'make:dto'        => ['App\DTO\ExampleDTO',                     'app/DTO/'],
        ];
    }

    #[DataProvider('generatorProvider')]
    public function testGeneratorOutputDirectoryMatchesPsr4Resolution(string $class, string $writesTo): void
    {
        $expected = $this->resolveDirectory($class);

        self::assertNotNull($expected, "No PSR-4 prefix matches {$class}.");
        self::assertSame(
            $expected,
            $writesTo,
            sprintf(
                'A class named %s autoloads from "%s" but the generator writes to "%s". '
                . 'On a case-sensitive filesystem the generated class will not be found.',
                $class,
                $expected,
                $writesTo
            )
        );
    }

    #[DataProvider('generatorProvider')]
    public function testTheGeneratorSourceUsesTheExpectedPath(string $class, string $writesTo): void
    {
        $commands = (string) file_get_contents($this->root . '/systems/Core/Console/Commands.php');

        self::assertStringContainsString(
            "ROOT_DIR . '" . $writesTo . "'",
            $commands,
            sprintf('Commands.php no longer writes %s output to %s.', $class, $writesTo)
        );
    }

    public function testNoGeneratorWritesToACapitalisedDirectory(): void
    {
        $commands = (string) file_get_contents($this->root . '/systems/Core/Console/Commands.php');

        foreach (['app/Models/', 'app/Jobs/', 'app/Repositories/', 'app/Services/', 'app/console/Commands/'] as $bad) {
            self::assertStringNotContainsString(
                "ROOT_DIR . '" . $bad . "'",
                $commands,
                sprintf('A generator writes to "%s". app/ directories are lowercase (DTO excepted).', $bad)
            );
        }
    }

    public function testAppDirectoriesAreLowercaseExceptDto(): void
    {
        $dirs = array_merge(
            glob($this->root . '/app/*', GLOB_ONLYDIR) ?: [],
            glob($this->root . '/app/*/*', GLOB_ONLYDIR) ?: []
        );

        self::assertNotEmpty($dirs);

        foreach ($dirs as $dir) {
            $name = basename($dir);

            // DTO is the agreed acronym exception. Auth and Filesystem are PSR-4
            // namespace roots under App\Support\; Phase 2 relocates Auth to Core.
            if (in_array($name, ['DTO', 'Auth', 'Filesystem'], true)) {
                continue;
            }

            self::assertSame(
                strtolower($name),
                $name,
                sprintf('app/ directories are lowercase (DTO excepted); found "%s".', $name)
            );
        }
    }

    public function testExistingSourceDirectoriesMatchTheirPsr4CaseExactly(): void
    {
        foreach ($this->psr4 as $prefix => $dir) {
            $path = $this->root . '/' . rtrim((string) $dir, '/');

            if (!is_dir($path)) {
                continue; // not created yet — generators mkdir on demand
            }

            $actual = basename((string) realpath($path));
            $declared = basename(rtrim((string) $dir, '/'));

            self::assertSame(
                $declared,
                $actual,
                sprintf('PSR-4 maps %s to "%s" but the directory on disk is "%s".', $prefix, $declared, $actual)
            );
        }
    }
}
