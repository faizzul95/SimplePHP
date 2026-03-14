<?php

namespace Core\Console;

/**
 * Myth — Laravel Artisan-like static facade for console commands.
 *
 * Mirrors Laravel's Artisan facade API:
 *   Myth::call(), Myth::callSilently(), Myth::queue(),
 *   Myth::output(), Myth::has(), Myth::all(), Myth::command(),
 *   Myth::starting(), Myth::terminate()
 */
class Myth
{
    private static ?Kernel $kernel = null;

    /** @var callable[] Bootstrap callbacks registered via starting() */
    private static array $bootstrapCallbacks = [];

    /** @var bool Whether bootstrap callbacks have already fired */
    private static bool $bootstrapCallbacksFired = false;

    /**
     * Set the Kernel instance (called by Kernel::bootstrap()).
     * Prevents Myth from creating a duplicate Kernel.
     */
    public static function setKernel(Kernel $kernel): void
    {
        self::$kernel = $kernel;

        // Fire any pending starting() callbacks now that we have a kernel
        if (!self::$bootstrapCallbacksFired && !empty(self::$bootstrapCallbacks)) {
            self::$bootstrapCallbacksFired = true;
            foreach (self::$bootstrapCallbacks as $cb) {
                $cb($kernel);
            }
        }
    }

    /**
     * Get or create the Kernel singleton.
     */
    private static function kernel(): Kernel
    {
        if (self::$kernel === null) {
            self::$kernel = new Kernel();
        }

        return self::$kernel;
    }

    // ─── Command Execution ────────────────────────────────────

    /**
     * Call a console command programmatically.
     *
     * Examples:
     *   Myth::call('down', ['secret' => 'abc']);
     *   Myth::call('backup:run --only-files');
     *
     * @param string $commandLine Command name (may include inline arguments/options)
     * @param array  $parameters  Named parameters ['key' => 'value']
     * @return int Exit code (0 = success)
     */
    public static function call(string $commandLine, array $parameters = []): int
    {
        return self::kernel()->call($commandLine, $parameters);
    }

    /**
     * Call a console command without printing output.
     *
     * @param string $commandLine Command name
     * @param array  $parameters  Named parameters
     * @return int Exit code
     */
    public static function callSilently(string $commandLine, array $parameters = []): int
    {
        return self::kernel()->callSilently($commandLine, $parameters);
    }

    /**
     * Alias for callSilently() — matches Laravel's Artisan::callSilent().
     *
     * @param string $commandLine Command name
     * @param array  $parameters  Named parameters
     * @return int Exit code
     */
    public static function callSilent(string $commandLine, array $parameters = []): int
    {
        return self::callSilently($commandLine, $parameters);
    }

    /**
     * Queue a command for background execution via the queue system.
     * Requires the queue driver to be configured (database or sync).
     *
     * Usage:
     *   Myth::queue('backup:run --only-db');
     *   Myth::queue('mail:send', ['user' => 42], 'emails');
     *
     * @param string $commandLine Command name
     * @param array  $parameters  Named parameters
     * @param string $queue       Queue name (default: 'default')
     * @return string|null Job ID or null
     */
    public static function queue(string $commandLine, array $parameters = [], string $queue = 'default'): ?string
    {
        $job = new CallQueuedCommand($commandLine, $parameters);
        $job->onQueue($queue);

        return dispatch($job);
    }

    // ─── Output ───────────────────────────────────────────────

    /**
     * Get output captured from the last call()/callSilently() invocation.
     */
    public static function output(): string
    {
        return self::kernel()->output();
    }

    // ─── Command Registration ─────────────────────────────────

    /**
     * Register a closure-based console command.
     * Equivalent to $console->command() but via the static facade.
     *
     * Usage:
     *   Myth::command('inspire', function($args, $opts) {
     *       echo "Be yourself; everyone else is taken.";
     *   }, 'Display an inspiring quote');
     *
     * @param string   $name        Command name/signature
     * @param callable $handler     The command handler
     * @param string   $description Human-readable description
     * @return void
     */
    public static function command(string $name, callable $handler, string $description = ''): void
    {
        $k = self::kernel();
        $k->bootstrap();
        $k->command($name, $handler, $description);
    }

    // ─── Introspection ────────────────────────────────────────

    /**
     * Determine if a command exists (built-in or registered).
     */
    public static function has(string $command): bool
    {
        $k = self::kernel();
        $k->bootstrap();
        return $k->has($command);
    }

    /**
     * Get all available command names (sorted).
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $k = self::kernel();
        $k->bootstrap();
        return $k->all();
    }

    // ─── Lifecycle ────────────────────────────────────────────

    /**
     * Register a callback to run when the Kernel bootstraps.
     * If the Kernel has already bootstrapped, the callback fires immediately.
     *
     * This mirrors Laravel's Artisan::starting() which lets packages
     * register commands during bootstrap.
     *
     * Usage:
     *   Myth::starting(function (Kernel $kernel) {
     *       $kernel->command('custom:cmd', fn() => ..., 'My command');
     *   });
     *
     * @param callable $callback Receives the Kernel instance
     * @return void
     */
    public static function starting(callable $callback): void
    {
        self::$bootstrapCallbacks[] = $callback;

        // If kernel already exists and is bootstrapped, fire immediately
        if (self::$kernel !== null) {
            $callback(self::$kernel);
        }
    }

    /**
     * Terminate the console application.
     * Runs any cleanup logic and resets the facade state.
     *
     * @param int $exitCode The exit code from the last command
     * @return void
     */
    public static function terminate(int $exitCode = 0): void
    {
        // Reset state for long-running processes or testing
        self::$kernel = null;
        self::$bootstrapCallbacksFired = false;
    }

    /**
     * Run from raw argv tokens (advanced use — like calling `php myth ...` programmatically).
     *
     * @param array $argv The argv array
     * @return int Exit code
     */
    public static function run(array $argv): int
    {
        return self::kernel()->handle($argv);
    }
}
