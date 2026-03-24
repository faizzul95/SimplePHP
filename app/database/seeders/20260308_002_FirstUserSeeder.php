<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'users';
    protected string $connection = 'default';

    public function run(): void
    {
        $users = [
            [
                'id' => 1,
                'name' => 'Super Administrator',
                'user_preferred_name' => 'superadmin',
                'email' => 'superadmin@admin.com',
                'user_gender' => 1,
                'user_dob' => '1990-01-01',
                'user_contact_no' => '0123456789',
                'username' => 'superadmin',
                'password' => '$2a$12$YhBi14Zkk1y9LpA3nOU8qOIgfk5j8pOxBYj7GsybkmfChVcCO7U3S',
                'user_status' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'name' => 'Administrator',
                'user_preferred_name' => 'admin',
                'email' => 'admin@admin.com',
                'user_gender' => 1,
                'user_dob' => '1992-06-15',
                'user_contact_no' => '0198765432',
                'username' => 'admin',
                'password' => '$2a$12$YhBi14Zkk1y9LpA3nOU8qOIgfk5j8pOxBYj7GsybkmfChVcCO7U3S',
                'user_status' => 1,
                'deleted_at' => null,
            ],
        ];

        $profiles = [
            [
                'user_id' => 1,
                'role_id' => 1,
                'is_main' => 1,
                'profile_status' => 1,
                'deleted_at' => null,
            ],
            [
                'user_id' => 2,
                'role_id' => 2,
                'is_main' => 1,
                'profile_status' => 1,
                'deleted_at' => null,
            ],
        ];

        $avatars = [
            [
                'files_name' => 'superadmin_avatar.jpg',
                'files_original_name' => 'superadmin_avatar.jpg',
                'files_type' => 'avatar',
                'files_mime' => 'image/jpeg',
                'files_extension' => 'jpg',
                'files_size' => 102400,
                'files_compression' => 0,
                'files_folder' => 'uploads/avatars',
                'files_path' => 'uploads/avatars/superadmin_avatar.jpg',
                'files_disk_storage' => 'public',
                'files_path_is_url' => 0,
                'files_description' => 'Super Administrator profile picture',
                'entity_type' => 'users',
                'entity_id' => 1,
                'entity_file_type' => 'avatar',
                'user_id' => 1,
            ],
            [
                'files_name' => 'admin_avatar.jpg',
                'files_original_name' => 'admin_avatar.jpg',
                'files_type' => 'avatar',
                'files_mime' => 'image/jpeg',
                'files_extension' => 'jpg',
                'files_size' => 98304,
                'files_compression' => 0,
                'files_folder' => 'uploads/avatars',
                'files_path' => 'uploads/avatars/admin_avatar.jpg',
                'files_disk_storage' => 'public',
                'files_path_is_url' => 0,
                'files_description' => 'Administrator profile picture',
                'entity_type' => 'users',
                'entity_id' => 2,
                'entity_file_type' => 'avatar',
                'user_id' => 2,
            ],
        ];

        foreach ($users as $user) {
            $this->insertOrUpdate($this->table, ['id' => $user['id']], $user);
        }

        foreach ($profiles as $profile) {
            $this->insertOrUpdate('user_profile', [
                'user_id' => $profile['user_id'],
                'role_id' => $profile['role_id'],
                'is_main' => $profile['is_main'],
            ], $profile);
        }

        foreach ($avatars as $avatar) {
            $this->insertOrUpdate('entity_files', [
                'entity_type' => $avatar['entity_type'],
                'entity_id' => $avatar['entity_id'],
                'entity_file_type' => $avatar['entity_file_type'],
                'user_id' => $avatar['user_id'],
            ], $avatar);
        }
    }
};
