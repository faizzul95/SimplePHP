<?php

namespace App\Http\Controllers\Api;

use Core\Http\Request;

class AuthApiController
{
    public function login(Request $request): array
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (empty($email) || empty($password)) {
            return ['code' => 400, 'message' => 'Email and password required'];
        }

        // Check failed login attempts (same logic as web AuthController)
        $ip = $request->ip();
        $countAttempt = db()->table('system_login_attempt')
            ->selectRaw('COUNT(*) as count')
            ->where('ip_address', $ip)
            ->whereRaw('time > NOW() - INTERVAL 10 MINUTE')
            ->fetch();

        if (($countAttempt['count'] ?? 0) >= 5) {
            return ['code' => 429, 'message' => 'Too many login attempts. Please try again later.'];
        }

        // Use Auth component instead of raw SQL
        $user = auth()->attempt(['email' => $email, 'password' => $password]);

        // Fallback: try username if email didn't match
        if ($user === false && $email !== null) {
            $user = auth()->attempt(['username' => $email, 'password' => $password]);
        }

        if ($user === false) {
            // Record failed attempt
            db()->table('system_login_attempt')->insert([
                'ip_address' => $ip,
                'user_id'    => 0,
                'user_agent' => $request->userAgent(),
                'time'       => timestamp(),
            ]);

            return ['code' => 401, 'message' => 'Invalid credentials'];
        }

        // Clear previous failed attempts on success
        db()->table('system_login_attempt')->where('ip_address', $ip)->delete();

        $tokenTtl = 30 * 24 * 60 * 60;
        $token = auth()->createToken((int) $user['id'], 'API Token', time() + $tokenTtl, ['*']);

        return [
            'code' => 200,
            'message' => 'Login',
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $tokenTtl,
        ];
    }

    public function me(): array
    {
        return [
            'code' => 200,
            'data' => auth()->tokenUser(),
        ];
    }

    public function logout(): array
    {
        if (!auth()->revokeCurrentToken()) {
            return ['code' => 400, 'message' => 'No active token'];
        }

        return ['code' => 200, 'message' => 'Token revoked'];
    }


}
