<?php

/*
|--------------------------------------------------------------------------
| ENVIRONMENT CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['environment'] = 'development'; // development, staging, production
$config['environment'] = (string) env('APP_ENV', $config['environment']);

/*
|--------------------------------------------------------------------------
| TIMEZONE CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['timezone'] = 'Asia/Kuala_Lumpur';
$config['timezone'] = (string) env('APP_TIMEZONE', $config['timezone']);

/*
|--------------------------------------------------------------------------
| DEBUG CONFIGURATION
|--------------------------------------------------------------------------
*/
$config['error_debug']    = (bool) env('APP_DEBUG', false);
$config['error_log_path'] = 'logs/error.log';
$config['error_log_path'] = (string) env('APP_ERROR_LOG_PATH', $config['error_log_path']);

/*
|--------------------------------------------------------------------------
| SESSIONS
|--------------------------------------------------------------------------
*/
$config['sess_regenerate_destroy'] = TRUE;
$config['sess_time_to_update'] = 300;

/*
|--------------------------------------------------------------------------
| ASSETS
|--------------------------------------------------------------------------
*/
$config['assets'] = [
	'versioning' => (bool) env('ASSET_VERSIONING_ENABLED', true),
	'cache_ttl' => (int) env('ASSET_VERSION_CACHE_TTL', 3600),
	'sri_algorithm' => (string) env('ASSET_SRI_ALGORITHM', 'sha384'),
	'fallback_version' => (string) env('APP_VERSION', 'dev'),
];