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