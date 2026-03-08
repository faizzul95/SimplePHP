<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use Core\Http\Request;

class UserApiController
{
    public function index(Request $request): array
    {
        $limit = (int) $request->input('limit', 20);
        $limit = ($limit > 0 && $limit <= 100) ? $limit : 20;

        $data = db()->table('users')
            ->select('id, name, email, username, user_status, created_at')
            ->whereNull('deleted_at')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->safeOutput()
            ->get();

        return [
            'code' => 200,
            'data' => $data,
        ];
    }

    public function store(StoreUserRequest $request): array
    {
        $payload = $request->validated();
        $userStatus = array_key_exists('user_status', $payload) ? (int) $payload['user_status'] : 1;

        $exists = db()->table('users')
            ->select('id')
            ->whereRaw('(email = ? OR username = ?)', [$payload['email'], $payload['username']])
            ->whereNull('deleted_at')
            ->fetch();

        if (!empty($exists)) {
            return [
                'code' => 409,
                'message' => 'Email or username already exists',
            ];
        }

        $insert = db()->table('users')->insert([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'username' => $payload['username'],
            'password' => password_hash($payload['password'], PASSWORD_DEFAULT),
            'user_status' => $userStatus,
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]);

        if (!isSuccess($insert['code'] ?? 500)) {
            return [
                'code' => 422,
                'message' => 'Failed to create user',
            ];
        }

        return [
            'code' => 201,
            'message' => 'User created',
        ];
    }

    public function show(string $id): array
    {
        $data = db()->table('users')
            ->select('id, name, email, username, user_status, created_at, updated_at')
            ->where('id', (int) $id)
            ->whereNull('deleted_at')
            ->safeOutput()
            ->fetch();

        if (empty($data)) {
            return [
                'code' => 404,
                'message' => 'User not found',
            ];
        }

        return [
            'code' => 200,
            'data' => $data,
        ];
    }

    public function update(UpdateUserRequest $request, string $id): array
    {
        $payload = $request->validated();

        $exists = db()->table('users')
            ->where('id', (int) $id)
            ->whereNull('deleted_at')
            ->fetch();

        if (empty($exists)) {
            return [
                'code' => 404,
                'message' => 'User not found',
            ];
        }

        $conflict = db()->table('users')
            ->select('id')
            ->where('id', '!=', (int) $id)
            ->whereRaw('(email = ? OR username = ?)', [$payload['email'], $payload['username']])
            ->whereNull('deleted_at')
            ->fetch();

        if (!empty($conflict)) {
            return [
                'code' => 409,
                'message' => 'Email or username already exists',
            ];
        }

        $updateData = [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'username' => $payload['username'],
            'updated_at' => timestamp(),
        ];

        if (array_key_exists('password', $payload) && $payload['password'] !== null && $payload['password'] !== '') {
            $updateData['password'] = password_hash($payload['password'], PASSWORD_DEFAULT);
        }

        if (array_key_exists('user_status', $payload) && $payload['user_status'] !== null) {
            $updateData['user_status'] = (int) $payload['user_status'];
        }

        $update = db()->table('users')->where('id', (int) $id)->update($updateData);

        if (!isSuccess($update['code'] ?? 500)) {
            return [
                'code' => 422,
                'message' => 'Failed to update user',
            ];
        }

        return [
            'code' => 200,
            'message' => 'User updated',
        ];
    }

    public function destroy(string $id): array
    {
        $exists = db()->table('users')
            ->where('id', (int) $id)
            ->whereNull('deleted_at')
            ->fetch();

        if (empty($exists)) {
            return [
                'code' => 404,
                'message' => 'User not found',
            ];
        }

        $delete = db()->table('users')->where('id', (int) $id)->softDelete();

        if (!isSuccess($delete['code'] ?? 500)) {
            return [
                'code' => 422,
                'message' => 'Failed to delete user',
            ];
        }

        return [
            'code' => 200,
            'message' => 'User deleted',
        ];
    }
}
