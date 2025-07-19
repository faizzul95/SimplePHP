<?php

use Components\Api;

$pdo = db()->getPdo();
$api = new Api($pdo, $config['api']);

// ===== ROUTE DEFINITIONS =====

include_once 'v1/auth.php';