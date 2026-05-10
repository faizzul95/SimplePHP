<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function showLogin(Request $request): void
    {
        $this->view('auth.login');
    }

    public function authorize(Request $request): array
    {
        return $this->authorizeByMode($request, 'session', true, ['session']);
    }

    public function loginApi(Request $request): array
    {
        $credentialMethods = auth()->apiCredentialMethods();
        if (empty($credentialMethods)) {
            return [
                'code' => 400,
                'message' => 'API credential login is not enabled for token or OAuth2 in auth config',
            ];
        }

        return $this->authorizeByMode($request, auth()->preferredApiMethod($credentialMethods), false, $credentialMethods);
    }

    public function me(Request $request): array
    {
        $methods = auth()->apiMethods();
        $user = auth()->user($methods);

        if (empty($user)) {
            return [
                'code' => 401,
                'message' => 'Unauthorized',
            ];
        }

        return [
            'code' => 200,
            'auth_via' => auth()->via($methods),
            'data' => $user,
        ];
    }

    public function devices(Request $request): array
    {
        $sessionVia = auth()->via(['oauth', 'session']);
        if ($sessionVia === null) {
            return [
                'code' => 400,
                'message' => 'Active auth method does not support browser session devices',
            ];
        }

        $userId = auth()->id('session');
        if ($userId === null || $userId < 1) {
            return [
                'code' => 401,
                'message' => 'Unauthorized',
            ];
        }

        return [
            'code' => 200,
            'auth_via' => $sessionVia,
            'data' => auth()->sessions($userId),
        ];
    }

    public function revokeDevice(string $sessionId): array
    {
        $sessionVia = auth()->via(['oauth', 'session']);
        if ($sessionVia === null) {
            return [
                'code' => 400,
                'message' => 'Active auth method does not support browser session devices',
            ];
        }

        if (!auth()->revokeSession($sessionId)) {
            return [
                'code' => 404,
                'message' => 'Session not found',
            ];
        }

        return [
            'code' => 200,
            'message' => 'Session revoked',
            'auth_via' => $sessionVia,
        ];
    }

    public function logoutOtherDevices(Request $request): array
    {
        $sessionVia = auth()->via(['oauth', 'session']);
        if ($sessionVia === null) {
            return [
                'code' => 400,
                'message' => 'Active auth method does not support browser session devices',
            ];
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            return [
                'code' => 400,
                'message' => 'Password is required',
            ];
        }

        if (!auth()->logoutOtherDevices($password)) {
            return [
                'code' => 400,
                'message' => 'Unable to log out other devices',
            ];
        }

        return [
            'code' => 200,
            'message' => 'Other devices logged out',
            'auth_via' => $sessionVia,
        ];
    }

    public function tokens(Request $request): array
    {
        $userId = auth()->id();
        if ($userId === null || $userId < 1) {
            return [
                'code' => 401,
                'message' => 'Unauthorized',
            ];
        }

        return [
            'code' => 200,
            'auth_via' => auth()->via(),
            'data' => auth()->tokens($userId),
        ];
    }

    public function currentToken(Request $request): array
    {
        if (auth()->via(['token']) !== 'token') {
            return [
                'code' => 400,
                'message' => 'No active personal access token',
            ];
        }

        $token = auth()->currentToken();
        if ($token === null) {
            return [
                'code' => 404,
                'message' => 'Token not found',
            ];
        }

        return [
            'code' => 200,
            'auth_via' => 'token',
            'data' => $token,
        ];
    }

    public function rotateCurrentToken(Request $request): array
    {
        if (auth()->via(['token']) !== 'token') {
            return [
                'code' => 400,
                'message' => 'No active personal access token',
            ];
        }

        $plainToken = auth()->bearerToken();
        if (!is_string($plainToken) || trim($plainToken) === '') {
            return [
                'code' => 400,
                'message' => 'No active personal access token',
            ];
        }

        $tokenName = trim((string) $request->input('token_name', ''));
        $expiresAt = $this->resolveRotatedTokenExpiry($request);
        $abilities = $this->resolveRotatedTokenAbilities($request);
        $replacement = auth()->rotateToken($plainToken, $tokenName, $expiresAt, $abilities);
        if ($replacement === null) {
            return [
                'code' => 500,
                'message' => 'Failed to rotate access token',
            ];
        }

        $response = [
            'code' => 200,
            'message' => 'Token rotated',
            'auth_via' => 'token',
            'token' => $replacement,
            'token_type' => 'Bearer',
        ];

        if ($expiresAt !== null) {
            $response['expires_in'] = max(0, $expiresAt - time());
        }

        return $response;
    }

    private function authorizeByMode(Request $request, string $defaultAuthMode = 'session', bool $appendRedirectUrl = true, array $allowedModes = ['session']): array
    {
        $username = trim((string) $request->input('username', $request->input('email', '')));
        $password = (string) $request->input('password');
        $authMode = strtolower((string) $request->input('auth_mode', $defaultAuthMode));
        $preferredApiMethod = auth()->preferredApiMethod();
        $allowedModes = array_values(array_unique(array_filter(array_map(static fn($mode) => strtolower(trim((string) $mode)), $allowedModes))));

        if (!in_array($authMode, $allowedModes, true)) {
            $authMode = $defaultAuthMode;
        }

        if ($username === '' || $password === '') {
            return ['code' => 400, 'message' => 'Invalid username or password'];
        }

        $credentials = ['password' => $password];
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $credentials['email'] = $username;
        } else {
            $credentials['username'] = $username;
        }

        $response = ['code' => 400, 'message' => 'Invalid username or password'];
        $userData = auth()->attempt($credentials);

        if ($userData === false) {
            $attemptStatus = auth()->lastAttemptStatus();
            if (!empty($attemptStatus)) {
                $response = [
                    'code' => (int) ($attemptStatus['http_code'] ?? 400),
                    'message' => (string) ($attemptStatus['message'] ?? 'Invalid username or password'),
                    'reason' => (string) ($attemptStatus['reason'] ?? 'invalid_credentials'),
                ];

                $context = (array) ($attemptStatus['context'] ?? []);
                if (!empty($context)) {
                    $response['context'] = $context;
                }
            }
        } else {
            $issuesApiCredential = in_array($authMode, ['token', 'oauth2'], true);
            $startsSession = $authMode === 'session';

            if ($startsSession) {
                $response = $this->loginSessionStart($request, $userData);
            } else {
                auth()->recordLoginHistory((int) $userData['id']);
                $response = ['code' => 200, 'message' => 'Login'];
            }

            if (isSuccess($response['code']) && $issuesApiCredential) {
                $credentialMethod = $authMode === 'session' ? $preferredApiMethod : $authMode;
                $credentialName = $credentialMethod === 'oauth2'
                    ? (string) $request->input('token_name', 'OAuth2 Access Token')
                    : (string) $request->input('token_name', 'Access Token');
                $maxTTL = 30 * 24 * 60 * 60;
                $credentialTTL = min(max((int) $request->input('token_ttl', $maxTTL), 60), $maxTTL);
                $abilities = auth()->permissions((int) $userData['id'], false);
                $credential = auth()->issueApiCredential(
                    (int) $userData['id'],
                    $credentialMethod,
                    $credentialName,
                    time() + $credentialTTL,
                    !empty($abilities) ? $abilities : ['*']
                );

                if ($credential === null) {
                    if ($startsSession) {
                        auth()->logout(false);
                    }

                    $response = ['code' => 500, 'message' => 'Failed to generate access credential'];
                } else {
                    $response['token'] = $credential['credential'];
                    $response['token_type'] = (string) ($credential['token_type'] ?? 'Bearer');
                    $response['expires_in'] = $credentialTTL;
                    $response['auth_via'] = (string) ($credential['method'] ?? $credentialMethod);
                }
            }
        }

        if ($appendRedirectUrl && isSuccess($response['code']) && empty($response['redirectUrl'])) {
            $response['redirectUrl'] = menu_manager()->resolveAuthenticatedLandingUrl() ?? url('dashboard');
        }

        return $response;
    }

    public function logout(Request $request): array
    {
        $apiMethods = auth()->apiMethods();
        $apiVia = auth()->via($apiMethods);
        $sessionVia = auth()->via(['oauth', 'session']);

        if (in_array($apiVia, ['token', 'oauth2'], true)) {
            if (!auth()->revokeCurrentApiCredential($apiMethods)) {
                return ['code' => 400, 'message' => $apiVia === 'oauth2' ? 'No active OAuth2 token' : 'No active token'];
            }

            return ['code' => 200, 'message' => $apiVia === 'oauth2' ? 'OAuth2 token revoked' : 'Token revoked', 'auth_via' => $apiVia];
        }

        if ($request->expectsJson() && $apiVia !== null) {
            return [
                'code' => 400,
                'message' => 'Logout is not supported for the active API auth method',
                'auth_via' => $apiVia,
            ];
        }

        auth()->logout(true);

        return [
            'code' => 200,
            'message' => 'Logout',
            'redirectUrl' => url(REDIRECT_LOGIN),
            'auth_via' => $sessionVia ?: 'session',
        ];
    }

    public function resetPassword(Request $request): array
    {
        $id = $request->input('id');
        if ($id === null || $id === '') {
            return ['code' => 400, 'message' => 'User ID is required'];
        }

        $result = db()->table('users')->where('id', $id)->whereNotNull('email')->safeOutput()->fetch();
        if (empty($result)) {
            return ['code' => 400, 'message' => 'User not found'];
        }

        $emailTemplate = db()->table('master_email_templates')
            ->where('email_type', 'RESET_PASSWORD')
            ->where('email_status', 1)
            ->safeOutputWithException(['email_body'])
            ->fetch();
        if (empty($emailTemplate)) {
            return ['code' => 400, 'message' => 'No email template found'];
        }

        $email = $result['email'];
        $name = $result['name'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['code' => 400, 'message' => 'Invalid email address'];
        }

        try {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            $newPassword = '';
            $charLen = strlen($chars) - 1;

            for ($i = 0; $i < 12; $i++) {
                $newPassword .= $chars[random_int(0, $charLen)];
            }

            $hashedPassword = \Core\Security\Hasher::make($newPassword);
            $appName = defined('APP_NAME') ? APP_NAME : 'Our Application';

            $update = db()->table('users')->where('id', $id)->update(['password' => $hashedPassword, 'updated_at' => timestamp()]);
            if (!isSuccess($update['code'])) {
                return ['code' => 400, 'message' => 'Failed to update password'];
            }

            $recipientData = [
                'recipient_email' => $email,
                'recipient_name' => $name,
            ];

            $emailBody = replaceTextWithData($emailTemplate['email_body'], [
                'new_password' => $newPassword,
                'user_fullname' => $name,
                'current_year' => date('Y'),
                'app_name' => $appName,
            ]);

            $send = sendEmail($recipientData, $emailTemplate['email_subject'], $emailBody);
            if (!empty($send['success'])) {
                return ['code' => 200, 'message' => 'Email has been sent to customer'];
            }

            return ['code' => 400, 'message' => 'Failed to send email'];
        } catch (\Exception $e) {
            logger()->logException($e);
            return ['code' => 400, 'message' => 'Failed to reset password. Please try again later.'];
        }
    }

    private function loginSessionStart(Request $request, array $userData): array
    {
        $userID = (int) $userData['id'];
        $db = db();

        $userData = $db->table('users')
            ->select('id, name, user_preferred_name, email')
            ->where('id', $userID)
            ->withOne('profile', 'user_profile', 'user_id', 'id', function ($db) {
                $db->select('id, user_id, role_id')
                    ->where('profile_status', 1)
                    ->where('is_main', 1)
                    ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                        $db->select('id,role_name,role_rank')->where('role_status', 1)
                            ->with('permission', 'system_permission', 'role_id', 'id', function ($db) {
                                $db->select('id,role_id,abilities_id')
                                    ->withOne('abilities', 'system_abilities', 'id', 'abilities_id', function ($db) {
                                        $db->select('id,abilities_name,abilities_slug');
                                    });
                            });
                    });
            })
            ->withOne('avatar', 'entity_files', 'entity_id', 'id', function ($db) {
                $db->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression, files_folder')
                    ->where('entity_file_type', 'USER_PROFILE');
            })
            ->safeOutput()
            ->fetch();

        if (empty($userData['profile'])) {
            return [
                'code' => 400,
                'message' => 'No active profile, Please contact administrator',
            ];
        }

        $perm = $userData['profile']['roles']['permission'] ?? null;
        $avatar = isset($userData['avatar']['files_path']) ? getFilesCompression($userData['avatar']) : 'public/upload/default.jpg';

        $loginOk = auth()->login((int) $userData['id'], [
            'userID' => $userData['id'],
            'userFullName' => $userData['name'],
            'userNickname' => $userData['user_preferred_name'],
            'userEmail' => $userData['email'],
            'roleID' => $userData['profile']['role_id'],
            'roleRank' => $userData['profile']['roles']['role_rank'] ?? 0,
            'roleName' => $userData['profile']['roles']['role_name'] ?? null,
            'permissions' => getPermissionSlug($perm),
            'userAvatar' => $avatar,
            'isLoggedIn' => true,
        ]);

        if (!$loginOk) {
            return [
                'code' => 500,
                'message' => 'Failed to initialize session. Please try again.',
            ];
        }

        return [
            'code' => 200,
            'message' => 'Login',
            'redirectUrl' => menu_manager()->resolveAuthenticatedLandingUrl() ?? url('dashboard'),
        ];
    }

    private function resolveRotatedTokenExpiry(Request $request): ?int
    {
        if (!$request->has('token_ttl')) {
            return null;
        }

        $maxTTL = 30 * 24 * 60 * 60;
        $ttl = min(max((int) $request->input('token_ttl', $maxTTL), 60), $maxTTL);

        return time() + $ttl;
    }

    private function resolveRotatedTokenAbilities(Request $request): array
    {
        if (!$request->has('abilities')) {
            return [];
        }

        $abilities = $request->input('abilities', []);
        if (is_string($abilities)) {
            $abilities = array_map('trim', explode(',', $abilities));
        }

        if (!is_array($abilities)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn($ability) => trim((string) $ability), $abilities), static fn(string $ability): bool => $ability !== ''));
    }
}
