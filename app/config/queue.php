<?php

/**
 * Queue Configuration
 *
 * Configure how background jobs are dispatched and processed.
 *
 * Supported drivers: "database", "sync" (immediate execution)
 *
 * Database driver: Jobs are stored in a MySQL/MariaDB table and processed
 * by `php myth queue:work`. Failed jobs go to a separate table.
 *
 * Sync driver: Jobs execute immediately in the current process (useful
 * for development/testing). No worker needed.
 */

$config['queue'] = [

    /*
    |----------------------------------------------------------------------
    | Default Queue Driver
    |----------------------------------------------------------------------
    | "database" — Persisted in DB, processed by a background worker.
    | "sync"     — Execute immediately inline (no background processing).
    */
    'default' => 'database',

    /*
    |----------------------------------------------------------------------
    | Queue Connections
    |----------------------------------------------------------------------
    */
    'connections' => [

        'database' => [
            'driver'       => 'database',
            'table'        => 'system_jobs',
            'failed_table' => 'system_failed_jobs',
            'queue'        => 'default',
            'retry_after'  => 90,       // seconds before a reserved job is retried
        ],

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Worker Defaults
    |----------------------------------------------------------------------
    | Default values when running `php myth queue:work`.
    | Can be overridden with CLI flags: --sleep=5 --tries=5 --timeout=120
    */
    'worker' => [
        'sleep'   => 3,    // seconds to wait when no jobs available
        'tries'   => 3,    // max attempts before marking as failed
        'timeout' => 60,   // max seconds a single job may run
    ],

];
