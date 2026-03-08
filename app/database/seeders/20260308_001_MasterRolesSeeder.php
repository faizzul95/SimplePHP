<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'master_roles';
    protected string $connection = 'default';

    public function run(): void
    {
        $roles = [
            [
                'id' => 1,
                'role_name' => 'Super Administrator',
                'role_rank' => 9999,
                'role_status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => 2,
                'role_name' => 'Administrator',
                'role_rank' => 1000,
                'role_status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($roles as $role) {
            $this->insertOrUpdate($this->table, ['id' => $role['id']], $role);
        }
    }
};
