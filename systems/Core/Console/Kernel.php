<?php

namespace Core\Console;

/**
 * Console Kernel - Laravel-like Artisan command handler
 *
 * Supports:
 * - Named commands with signatures (e.g. make:controller {name} {--resource})
 * - Option/argument parsing (--key=value, --flag, positional args)
 * - Groups for organizing commands
 * - Colorized output
 * - Schedule registration for cron jobs
 * - Built-in commands: list, help, schedule:run, schedule:work, schedule:list
 */
class Kernel
{
    protected array $commands = [];
    protected Schedule $schedule;
    protected bool $bootstrapped = false;
    protected string $lastOutput = '';

    /** @var array<string, string> */
    private array $builtInCommands = [
        'list' => 'List all available commands',
        'help' => 'Show help for a command',
        'schedule:run' => 'Run all due scheduled commands',
        'schedule:work' => 'Run the scheduler worker in foreground [--once] [--max-cycles=N] [--max-memory=MB]',
        'schedule:list' => 'List all scheduled commands',
    ];

    /**
     * Bootstrap the console application.
     * Loads built-in framework commands, then user commands from console route file.
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->bootstrapped = true;

        // Register this Kernel with the Myth facade so it reuses
        // the same instance instead of creating a duplicate.
        Myth::setKernel($this);

        Commands::register($this);

        $console = $this;
        $framework = config('framework') ?? [];
        $consoleRouteFile = ROOT_DIR . ($framework['route_files']['console'] ?? 'app/routes/console.php');

        if (is_file($consoleRouteFile)) {
            require $consoleRouteFile;
        }

        $this->schedule($this->getSchedule());
    }

    /**
     * Define the application's command schedule.
     * Override this method to register scheduled tasks.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Override in subclass or define in console route file
    }

    /**
     * Handle the incoming console command.
     */
    public function handle(array $argv): int
    {
        $this->bootstrap();
        return $this->run($argv);
    }

    /**
     * Call a console command programmatically (Laravel-like Artisan::call style)
     */
    public function call(string $commandLine, array $parameters = []): int
    {
        $this->bootstrap();
        return $this->executeCall($commandLine, $parameters, false);
    }

    /**
     * Call a console command programmatically without writing output to STDOUT.
     */
    public function callSilently(string $commandLine, array $parameters = []): int
    {
        $this->bootstrap();
        return $this->executeCall($commandLine, $parameters, true);
    }

    /**
     * Get output captured from the last call()/callSilently() invocation.
     */
    public function output(): string
    {
        return $this->lastOutput;
    }

    /**
     * Determine if a command exists (built-in or registered).
     */
    public function has(string $command): bool
    {
        return isset($this->builtInCommands[$command]) || isset($this->commands[$command]);
    }

    /**
     * Get all available command names (built-in + registered).
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $all = array_unique(array_merge(array_keys($this->builtInCommands), array_keys($this->commands)));
        sort($all);
        return $all;
    }

    /**
     * Execute a command line with optional silent output mode.
     */
    private function executeCall(string $commandLine, array $parameters, bool $silent): int
    {
        $parts = $this->tokenizeCommandLine($commandLine);
        $commandName = $parts[0] ?? '';

        if ($commandName === '') {
            $this->error('Command cannot be empty.');
            return 1;
        }

        $tokens = $parts;

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $tokens[] = (string) $value;
                continue;
            }

            $optionName = '--' . trim((string) $key);
            if ($value === true) {
                $tokens[] = $optionName;
                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $tokens[] = $optionName . '=' . (string) $value;
            } else {
                $tokens[] = $optionName . '=' . json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        ob_start();
        $exitCode = $this->run(array_merge(['myth'], $tokens));
        $captured = (string) ob_get_clean();

        $this->lastOutput = $captured;

        if (!$silent && $captured !== '') {
            echo $captured;
        }

        return $exitCode;
    }

    /**
     * Tokenize command line while preserving quoted values.
     *
     * @return array<int, string>
     */
    private function tokenizeCommandLine(string $commandLine): array
    {
        $trimmed = trim($commandLine);
        if ($trimmed === '') {
            return [];
        }

        $tokens = str_getcsv($trimmed, ' ', '"');
        return array_values(array_filter($tokens, static function ($token) {
            return $token !== null && $token !== '';
        }));
    }

    /**
     * Register a console command
     */
    public function command(string $name, callable $handler, string $description = ''): self
    {
        $this->commands[$name] = [
            'handler' => $handler,
            'description' => $description,
        ];

        return $this;
    }

    /**
     * Register a scheduled command (backward-compatible shorthand)
     */
    public function scheduleCommand(string $command, string $cron = '', string $description = ''): self
    {
        $event = $this->schedule->command($command);
        if ($cron !== '') {
            $event->cron($cron);
        }
        if ($description !== '') {
            $event->description($description);
        }

        return $this;
    }

    /**
     * Get the Schedule instance for fluent API usage
     */
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function __construct()
    {
        $this->schedule = new Schedule();
    }

    /**
     * Run the console kernel
     */
    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'list';
        $rawArgs = array_slice($argv, 2);

        $parsed = $this->parseArguments($rawArgs);

        if ($commandName === 'list') {
            $this->printList();
            return 0;
        }

        if ($commandName === 'help') {
            $target = $parsed['args'][0] ?? null;
            $this->printHelp($target);
            return 0;
        }

        if ($commandName === 'schedule:run') {
            return $this->runSchedule();
        }

        if ($commandName === 'schedule:work') {
            return $this->runScheduleWork($parsed['options']);
        }

        if ($commandName === 'schedule:list') {
            $this->printScheduleList();
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            $this->error("Command '{$commandName}' not found.");
            $this->suggest($commandName);
            return 1;
        }

        try {
            $result = call_user_func($this->commands[$commandName]['handler'], $parsed['args'], $parsed['options']);
            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Parse CLI arguments into positional args and named options
     */
    public function parseArguments(array $rawArgs): array
    {
        $args = [];
        $options = [];

        foreach ($rawArgs as $arg) {
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $value] = explode('=', $option, 2);
                    $options[trim($key)] = $value;
                } else {
                    $options[trim($option)] = true;
                }
            } elseif (str_starts_with($arg, '-')) {
                $flags = substr($arg, 1);
                for ($i = 0; $i < strlen($flags); $i++) {
                    $options[$flags[$i]] = true;
                }
            } else {
                $args[] = $arg;
            }
        }

        return ['args' => $args, 'options' => $options];
    }

    /**
     * Check if a command is registered
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Get all registered commands
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get all schedule events
     *
     * @return ScheduleEvent[]
     */
    public function getScheduleEvents(): array
    {
        return $this->schedule->events();
    }

    /**
     * Run all due scheduled commands via the Schedule manager
     */
    private function runSchedule(): int
    {
        if ($this->schedule->isEmpty()) {
            $this->info('No scheduled commands registered.');
            return 0;
        }

        $dueEvents = $this->schedule->dueEvents();

        if (empty($dueEvents)) {
            $this->info('No scheduled commands are due.');
            return 0;
        }

        $this->info('Running ' . count($dueEvents) . ' due command(s)...');
        $this->line('');

        $result = $this->schedule->runDueEvents($this, $dueEvents);

        foreach ($result['results'] as $entry) {
            $label = $entry['command'];
            if (str_starts_with($label, '__closure_')) {
                $label = 'Closure';
            }

            switch ($entry['status']) {
                case 'success':
                    $desc = !empty($entry['description']) ? " ({$entry['description']})" : '';
                    $this->success("  ✓ {$label}{$desc}");
                    break;
                case 'failed':
                    $this->error("  ✗ {$label} — {$entry['error']}");
                    break;
                case 'skipped':
                    $this->warn("  ⊘ {$label} — skipped ({$entry['reason']})");
                    break;
            }
        }

        $this->line('');
        $summary = "Ran: {$result['ran']}";
        if ($result['failed'] > 0) {
            $summary .= ", Failed: {$result['failed']}";
        }
        if ($result['skipped'] > 0) {
            $summary .= ", Skipped: {$result['skipped']}";
        }
        $this->info($summary);

        return $result['failed'] > 0 ? 1 : 0;
    }

    /**
     * Run the scheduler worker in foreground (Laravel-like schedule:work)
     *
     * Options:
     *   --once               Run a single schedule check then exit
     *   --max-cycles=N       Stop after N minute-cycles (safe for supervisor restarts)
     *   --max-memory=MB      Stop when memory reaches MB limit
     */
    private function runScheduleWork(array $options = []): int
    {
        if (isset($options['once'])) {
            return $this->runSchedule();
        }

        $maxCycles = isset($options['max-cycles']) ? max(1, (int) $options['max-cycles']) : 0;
        $maxMemoryMb = isset($options['max-memory']) ? max(32, (int) $options['max-memory']) : 0;
        $cycles = 0;

        if (function_exists('gc_enable')) {
            gc_enable();
        }

        $this->info('Running scheduled tasks worker. Press Ctrl+C to stop.');

        $lastMinute = null;

        while (true) {
            $currentMinute = date('Y-m-d H:i');

            if ($currentMinute !== $lastMinute) {
                $lastMinute = $currentMinute;
                $this->comment('[' . date('Y-m-d H:i:s') . '] schedule:run');
                $this->runSchedule();
                $cycles++;

                if ($maxCycles > 0 && $cycles >= $maxCycles) {
                    $this->info("Reached max cycles ({$maxCycles}). Stopping scheduler worker.");
                    return 0;
                }

                if ($maxMemoryMb > 0) {
                    $usageMb = memory_get_usage(true) / 1024 / 1024;
                    if ($usageMb >= $maxMemoryMb) {
                        $this->warn("Memory limit reached ({$maxMemoryMb} MB). Stopping scheduler worker for safe restart.");
                        return 0;
                    }
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            sleep(1);
        }
    }

    /**
     * Print the command list
     */
    private function printList(): void
    {
        $this->line('');
        $this->info('SimplePHP Console');
        $this->line('');
        $this->warn('Usage:');
        $this->line('  php myth <command> [arguments] [options]');
        $this->line('');

        $groups = [];
        foreach ($this->commands as $name => $cmd) {
            $prefix = str_contains($name, ':') ? explode(':', $name, 2)[0] : 'general';
            $groups[$prefix][$name] = $cmd;
        }

        $groups['_builtin'] = [];
        foreach ($this->builtInCommands as $command => $description) {
            $groups['_builtin'][$command] = ['description' => $description];
        }

        ksort($groups);

        $this->warn('Available commands:');
        foreach ($groups as $group => $commands) {
            if ($group !== '_builtin' && $group !== 'general') {
                $this->line('');
                $this->info(" {$group}");
            }

            foreach ($commands as $name => $cmd) {
                $desc = !empty($cmd['description']) ? $cmd['description'] : '';
                $padding = str_repeat(' ', max(1, 30 - strlen($name)));
                $this->line("  \033[32m{$name}\033[0m{$padding}{$desc}");
            }
        }

        $this->line('');
    }

    /**
     * Print help for a specific command
     */
    private function printHelp(?string $command): void
    {
        if ($command === null) {
            $this->printList();
            return;
        }

        if (isset($this->builtInCommands[$command])) {
            $this->line('');
            $this->info("Command: {$command}");
            $this->line('  ' . $this->builtInCommands[$command]);
            $this->line('');
            $this->warn('Usage:');
            $this->line("  php myth {$command} [arguments] [options]");
            $this->line('');
            return;
        }

        if (!isset($this->commands[$command])) {
            $this->error("Command '{$command}' not found.");
            return;
        }

        $cmd = $this->commands[$command];
        $this->line('');
        $this->info("Command: {$command}");
        if (!empty($cmd['description'])) {
            $this->line('  ' . $cmd['description']);
        }
        $this->line('');
        $this->warn('Usage:');
        $this->line("  php myth {$command} [arguments] [options]");
        $this->line('');
    }

    /**
     * Suggest similar commands when a typo is detected
     */
    private function suggest(string $input): void
    {
        $suggestions = [];
        foreach ($this->all() as $name) {
            $distance = levenshtein($input, $name);
            if ($distance <= 3) {
                $suggestions[] = $name;
            }
        }

        if (!empty($suggestions)) {
            $this->line('');
            $this->warn('Did you mean?');
            foreach ($suggestions as $s) {
                $this->line("  {$s}");
            }
        }
    }

    /**
     * Print the schedule list with status
     */
    private function printScheduleList(): void
    {
        $events = $this->schedule->events();

        if (empty($events)) {
            $this->info('No scheduled commands registered.');
            return;
        }

        $rows = [];
        foreach ($events as $event) {
            $name = $event->getCommand();
            if (str_starts_with($name, '__closure_')) {
                $name = 'Closure';
            }
            $rows[] = [
                $event->getExpression(),
                $name,
                $event->getDescription(),
                $event->isDue() ? 'Due now' : 'Waiting',
            ];
        }

        $this->line('');
        $this->table(['Expression', 'Command', 'Description', 'Status'], $rows);
        $this->line('');
    }

    public function info(string $message): void
    {
        echo "\033[36m{$message}\033[0m" . PHP_EOL;
    }

    public function success(string $message): void
    {
        echo "\033[32m{$message}\033[0m" . PHP_EOL;
    }

    public function warn(string $message): void
    {
        echo "\033[33m{$message}\033[0m" . PHP_EOL;
    }

    public function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m" . PHP_EOL;
    }

    public function line(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    public function comment(string $message): void
    {
        echo "\033[90m{$message}\033[0m" . PHP_EOL;
    }

    public function task(string $label, bool $success = true, string $extra = ''): void
    {
        $maxWidth = 50;
        $dots = str_repeat('.', max(1, $maxWidth - strlen($label)));
        $status = $success ? "\033[32mDONE\033[0m" : "\033[31mFAIL\033[0m";
        $suffix = $extra !== '' ? " \033[90m({$extra})\033[0m" : '';

        echo "  {$label} {$dots} {$status}{$suffix}" . PHP_EOL;
    }

    public function ask(string $question, ?string $default = null): string
    {
        $defaultHint = $default !== null ? " [{$default}]" : '';
        echo "\033[33m{$question}{$defaultHint}:\033[0m ";

        $answer = trim((string) fgets(STDIN));
        return $answer !== '' ? $answer : ($default ?? '');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';
        echo "\033[33m{$question} {$hint}:\033[0m ";

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }

    public function table(array $headers, array $rows): void
    {
        $widths = [];

        foreach ($headers as $i => $header) {
            $widths[$i] = strlen((string) $header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        $separator = '+';
        foreach ($widths as $w) {
            $separator .= str_repeat('-', $w + 2) . '+';
        }

        $this->line($separator);
        $headerLine = '|';
        foreach ($headers as $i => $header) {
            $headerLine .= ' ' . str_pad((string) $header, $widths[$i]) . ' |';
        }
        $this->info($headerLine);
        $this->line($separator);

        foreach ($rows as $row) {
            $line = '|';
            foreach ($row as $i => $cell) {
                $line .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            $this->line($line);
        }

        $this->line($separator);
    }

    public function progress(int $current, int $total, string $label = ''): void
    {
        $percent = $total > 0 ? round(($current / $total) * 100) : 0;
        $bar = str_repeat('█', (int) ($percent / 2)) . str_repeat('░', 50 - (int) ($percent / 2));
        echo "\r  {$label} [{$bar}] {$percent}%";

        if ($current >= $total) {
            echo PHP_EOL;
        }
    }
}
