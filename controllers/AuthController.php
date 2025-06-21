<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| CREDENTIAL LOGIN
|--------------------------------------------------------------------------
*/

function authorize($request)
{
    // Example of how to get data from request using the $request
    // $username = $request['username'] ?? null;
    // $password = $request['password'] ?? null;

    // Example of how to get data from request using the request() helper (more secure)
    $username = request()->input('username');
    $password = request()->input('password');

    $response = ['code' => 400, 'message' => 'Invalid username or password']; // set default

    $userData = db()->query("SELECT `id`, `password` FROM `users` WHERE `email` = :0 OR `username` = :0", [$username])->fetch();

    if (!empty($userData)) {
        $ipUser = request()->ip();

        $countAttempt = db()->query("SELECT COUNT(*) as count FROM `system_login_attempt` WHERE `ip_address` = ? AND `time` > NOW() - INTERVAL 10 MINUTE AND `user_id` = ?", [$ipUser, $userData['id']])->fetch();
        if ($countAttempt['count'] >= 5) {
            $response = [
                'code' => 429,
                'message' => 'Too many login attempts. Please try again later.',
            ];
            jsonResponse($response);
        }

        if (password_verify($password, $userData['password'])) {
            $response = loginSessionStart($userData, 1);
            // Clear login attempts on successful login
            db()->table('system_login_attempt')->where('user_id', $userData['id'])->delete();
        } else {
            // Log the failed attempt
            db()->table('system_login_attempt')->insert([
                'ip_address' => $ipUser,
                'user_id' => $userData['id'],
                'user_agent' => request()->userAgent(),
                'time' => timestamp()
            ]);
        }
    }

    jsonResponse($response);
}

/*
|--------------------------------------------------------------------------
| SOCIALITE (GOOGLE/FB) LOGIN
|--------------------------------------------------------------------------
*/

function socialite($request)
{
    $email = request()->input('email');
    $userData = db()->table('users')->select('id')->where('email', $email)->fetch();
    // $userData = db()->query("SELECT `id` FROM `users` WHERE `email` = :0", [$email])->fetch();

    $response = ['code' => 400, 'message' => 'Email not found or email not registered!']; // set default
    if (hasData($userData)) {
        $response = loginSessionStart($userData, 2);
    }

    jsonResponse($response);
}

/*
|--------------------------------------------------------------------------
| SESSION MANAGEMENT
|--------------------------------------------------------------------------
*/

function loginSessionStart($userData, $loginType = 1)
{
    global $redirectAuth;
    $userID = $userData['id'];

    $db = db();
    $userData = $db->table('users')
        ->select('id, name, user_preferred_name, email')
        ->where('id', $userID)
        ->withOne('profile', 'user_profile', 'user_id', 'id', function ($db) {
            $db->select('id, user_id, role_id')
                ->where('profile_status', 1)
                ->where('is_main', 1)
                ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                    $db->select('id,role_name')->where('role_status', 1)
                        ->with('permission', 'system_permission', 'role_id', 'id', function ($db) {
                            $db->select('id,role_id,abilities_id')
                                ->withOne('abilities', 'system_abilities', 'id', 'abilities_id', function ($db) {
                                    $db->select('id,abilities_name,abilities_slug');
                                });
                        });
                })
                ->withOne('avatar', 'entity_files', 'entity_id', 'id', function ($db) {
                    $db->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression')
                        ->where('entity_file_type', 'USER_PROFILE');
                });
        })
        ->fetch();

    if (empty($userData['profile'])) {
        return [
            'code' => 400,
            'message' => 'No active profile, Please contact administrator'
        ];
    }

    // Add to login history
    $db->table('system_login_history')->insert([
        'user_id' => $userData['id'],
        'ip_address' => request()->ip(),
        'login_type' => $loginType,
        'operating_system' => request()->platform(),
        'browsers' => request()->browser(),
        'time' => timestamp(),
        'user_agent' => request()->userAgent(),
        'created_at' => timestamp(),
    ]);

    $perm = $userData['profile']['roles']['permission'] ?? null;

    // Set/start session data
    startSession([
        'userID' => $userData['id'],
        'userFullName' => $userData['name'],
        'userNickname' => $userData['user_preferred_name'],
        'userEmail' => $userData['email'],
        'roleID' => $userData['profile']['role_id'],
        'roleRank' => $userData['profile']['roles']['role_rank'] ?? 0,
        'roleName' => $userData['profile']['roles']['role_name'] ?? null,
        'permissions' => getPermissionSlug($perm),
        'userAvatar' => $userData['profile']['avatar']['files_path'] ?? 'general/images/user.png',
        'isLoggedIn' => true,
    ]);

    return [
        'code' => 200,
        'message' => 'Login',
        'redirectUrl' => $redirectAuth,
    ];
}

function logout()
{
    session_destroy();
    jsonResponse([
        'code' => 200,
        'message' => 'Logout',
        'redirectUrl' => REDIRECT_LOGIN,
    ]);
}
