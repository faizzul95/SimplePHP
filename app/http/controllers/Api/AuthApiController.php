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

        $identifier = trim((string) $email);
        $credentials = ['password' => $password];
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $credentials['email'] = $identifier;
        } else {
            $credentials['username'] = $identifier;
        }

        $user = auth()->attempt($credentials);

        if ($user === false) {
            $attemptStatus = auth()->lastAttemptStatus();

            if (!empty($attemptStatus)) {
                $response = [
                    'code' => (int) ($attemptStatus['http_code'] ?? 401),
                    'message' => (string) ($attemptStatus['message'] ?? 'Invalid credentials'),
                    'reason' => (string) ($attemptStatus['reason'] ?? 'invalid_credentials'),
                ];

                $context = (array) ($attemptStatus['context'] ?? []);
                if (!empty($context)) {
                    $response['context'] = $context;
                }

                return $response;
            }

            return ['code' => 401, 'message' => 'Invalid credentials'];
        }

        $tokenTtl = 30 * 24 * 60 * 60;
        $token = auth()->createToken((int) $user['id'], 'API Token', time() + $tokenTtl, ['*']);

        if (empty($token)) {
            return ['code' => 500, 'message' => 'Failed to generate access token'];
        }

        // login_type: 3 = token login (see system_login_history migration comment)
        auth()->recordLoginHistory((int) $user['id'], 3);

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
        $methods = auth()->apiMethods();

        return [
            'code' => 200,
            'data' => auth()->user($methods),
        ];
    }

    public function logout(): array
    {
        $methods = auth()->apiMethods();
        $via = auth()->via($methods);

        if ($via === 'token') {
            if (!auth()->revokeCurrentToken()) {
                return ['code' => 400, 'message' => 'No active token'];
            }

            return ['code' => 200, 'message' => 'Token revoked'];
        }

        if ($via === 'oauth2') {
            if (!auth()->revokeCurrentOAuth2Token()) {
                return ['code' => 400, 'message' => 'No active OAuth2 token'];
            }

            return ['code' => 200, 'message' => 'OAuth2 token revoked'];
        }

        return [
            'code' => 200,
            'message' => 'Authenticated session ended',
            'auth_via' => $via,
        ];
    }


}
