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
        global $redirectAuth;

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');
        $authMode = strtolower((string) $request->input('auth_mode', 'session'));

        if (!in_array($authMode, ['session', 'token', 'both'], true)) {
            $authMode = 'session';
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
            $sessionResponse = $this->loginSessionStart($request, $userData);
            $response = $sessionResponse;

            if (isSuccess($sessionResponse['code']) && in_array($authMode, ['token', 'both'], true)) {
                $tokenName = $request->input('token_name', 'Web Login Token');
                $maxTTL = 30 * 24 * 60 * 60; // 30 days maximum
                $tokenTTL = min(max((int) $request->input('token_ttl', $maxTTL), 60), $maxTTL);

                // Derive token abilities from user's actual permissions — never trust client input
                $tokenAbilities = !empty($_SESSION['permissions']) ? $_SESSION['permissions'] : ['*'];
                $token = auth()->createToken((int) $userData['id'], (string) $tokenName, time() + $tokenTTL, $tokenAbilities);

                if (empty($token)) {
                    $response = ['code' => 500, 'message' => 'Failed to generate access token'];
                } else {
                    if ($authMode === 'token') {
                        auth()->logout(false);

                        $response = [
                            'code' => 200,
                            'message' => 'Login',
                            'token' => $token,
                            'token_type' => 'Bearer',
                            'expires_in' => $tokenTTL,
                        ];
                    } else {
                        $response['token'] = $token;
                        $response['token_type'] = 'Bearer';
                        $response['expires_in'] = $tokenTTL;
                    }
                }
            }
        }

        if (isSuccess($response['code']) && empty($response['redirectUrl'])) {
            $response['redirectUrl'] = $redirectAuth;
        }

        return $response;
    }

    public function logout(Request $request): array
    {
        auth()->logout(true);
        return [
            'code' => 200,
            'message' => 'Logout',
            'redirectUrl' => url(REDIRECT_LOGIN),
        ];
    }

    public function resetPassword(Request $request): array
    {
        $id = decodeID($request->input('id'));
        if (empty($id)) {
            return ['code' => 400, 'message' => 'ID is required'];
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

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
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
            'redirectUrl' => function_exists('resolveAuthenticatedLandingUrl') ? (resolveAuthenticatedLandingUrl() ?? url('dashboard')) : url('dashboard'),
        ];
    }
}
