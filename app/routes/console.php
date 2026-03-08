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

$schedule->command('backup:run')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->description('Daily database & files backup at 2 AM')
    ->onFailure(function () {
        if (function_exists('logger')) {
            logger()->log_error('Scheduled backup failed');
        }
    });

$schedule->command('backup:clean')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->description('Weekly cleanup of old backups on Sunday 3 AM');

// Example: Process queue every minute
// $schedule->command('queue:work --once')
//     ->everyMinute()
//     ->description('Process next job in the queue');
