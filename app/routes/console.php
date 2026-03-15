<?php

/*
|--------------------------------------------------------------------------
| Console Routes — Custom Commands & Schedule
|--------------------------------------------------------------------------
|
| Register your custom CLI commands and scheduled tasks here.
| Built-in commands (make:*, cache:*, route:list, etc.) are loaded
| automatically by the framework — do NOT duplicate them here.
|
| Usage: php myth <command> [arguments] [--options]
|
| Available variables:
|   $console  — Core\Console\Kernel instance
|   $schedule — Core\Console\Schedule instance (for task scheduling)
|
*/

// ─── Custom Commands ─────────────────────────────────────────
// Register your application-specific commands below.
//
// Example:
//   $console->command('mail:send', function (array $args = [], array $options = []) use ($console) {
//       $console->info("  Sending emails...");
//       // your logic
//       $console->task('Email batch', true, '42 sent');
//   }, 'Send pending email notifications');

// ─── Schedule Registration ───────────────────────────────────
// Register scheduled tasks using the fluent Laravel-like API.
// Add to system crontab: * * * * * cd /path-to-project && php myth schedule:run >> /dev/null 2>&1

$schedule = $console->getSchedule();
$maintenanceSecret = getenv('MYTH_MAINTENANCE_SECRET') ?: (config('framework.maintenance.secret') ?? null);

// Similar to old Laravel Kernel: production-only backup scheduler
if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
    // Optional task: backup monitor (only if command exists)
    if ($console->hasCommand('backup:monitor')) {
        $schedule->command('backup:monitor')
            ->name('backup-monitor')
            ->withoutOverlapping(500)
            ->evenInMaintenanceMode()
            ->dailyAt('03:00');
    }

    // Cleanup old backups daily
    $schedule->command('backup:clean')
        ->name('backup-clean')
        ->withoutOverlapping(500)
        ->evenInMaintenanceMode()
        ->dailyAt('05:00')
        ->appendOutputTo(ROOT_DIR . 'logs/backup_clean.log');

    // Database backup daily (equivalent to old backup:database)
    $schedule->command('db:backup')
        ->name('backup-database')
        ->withoutOverlapping(500)
        ->evenInMaintenanceMode()
        ->dailyAt('12:01')
        ->appendOutputTo(ROOT_DIR . 'logs/backup_database.log');

    // Filesystem backup monthly (equivalent to old backup:filesystem)
    $schedule->command('backup:run --only-files')
        ->name('backup-file')
        ->evenInMaintenanceMode()
        ->monthly()
        ->appendOutputTo(ROOT_DIR . 'logs/backup_filesystem.log');

    // Full backup twice monthly with maintenance window around it
    $schedule->command('backup:run')
        ->twiceMonthly(1, 16, '04:30')
        ->name('backup-full')
        ->evenInMaintenanceMode()
        ->before(function () use ($maintenanceSecret) {
            if (\Core\Console\Myth::has('down')) {
                $downOptions = [
                    'refresh' => 60,
                    'retry' => 7200,
                ];

                if (is_string($maintenanceSecret) && trim($maintenanceSecret) !== '') {
                    $downOptions['secret'] = trim($maintenanceSecret);
                }

                \Core\Console\Myth::callSilently('down', $downOptions);
            }
        })
        ->after(function () {
            if (\Core\Console\Myth::has('up')) {
                \Core\Console\Myth::callSilently('up');
            }
        })
        ->appendOutputTo(ROOT_DIR . 'logs/backup_full.log')
        ->onFailure(function () {
            if (function_exists('logger')) {
                logger()->log_error('Scheduled full backup failed');
            }
        });
}

// Example: Process queue every minute
// $schedule->command('queue:work --once')
//     ->everyMinute()
//     ->description('Process next job in the queue');
