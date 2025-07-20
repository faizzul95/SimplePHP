<?php

use Components\Api;

$db = db();
$pdo = db()->getPdo();
$api = new Api($pdo, $config['api']);

// ===== ROUTE DEFINITIONS =====

include_once 'v1/system.php';
include_once 'v1/auth.php';

// Handle incoming request
$api->handleRequest();
