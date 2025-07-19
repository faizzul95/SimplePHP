<?php

$api->post('/v1/auth/login', function () use ($api, $db) {
    $input = $api->getJsonInput();

    // Validate input
    if (empty($input['email']) || empty($input['password'])) {
        http_response_code(400);
        return ['error' => 'Email and password required'];
    }

    $userData = $db->query("SELECT `id`, `password` FROM `users` WHERE `email` = :0 OR `username` = :0", [$input['email']])->fetch();

    if (!$userData) {
        http_response_code(401);
        return ['error' => 'Invalid credentials'];
    }

    if (!empty($userData)) {
        $ipUser = request()->ip();

        $countAttempt = $db->query("SELECT COUNT(*) as count FROM `system_login_attempt` WHERE `ip_address` = ? AND `time` > NOW() - INTERVAL 10 MINUTE AND `user_id` = ?", [$ipUser, $userData['id']])->fetch();
        if ($countAttempt['count'] >= 5) {
            $response = [
                'code' => 429,
                'message' => 'Too many login attempts. Please try again later.',
            ];
            jsonResponse($response);
        }

        if (password_verify($input['password'], $userData['password'])) {
            $response = loginSessionStart($userData, 1);
            // Clear login attempts on successful login
            $db->table('system_login_attempt')->where('user_id', $userData['id'])->delete();
        } else {
            // Log the failed attempt
            $db->table('system_login_attempt')->insert([
                'ip_address' => $ipUser,
                'user_id' => $userData['id'],
                'user_agent' => request()->userAgent(),
                'time' => timestamp()
            ]);
        }
    }

    $timeLimit = 30 * 24 * 60 * 60;

    // Generate token valid for 30 days
    $expiry = time() + $timeLimit;
    $token = $api->generateToken($userData['id'], 'Web App Token', $expiry, ['read', 'write']);

    return [
        'token' => $token,
        'expires_in' => $timeLimit, // seconds
        'token_type' => 'Bearer'
    ];
});