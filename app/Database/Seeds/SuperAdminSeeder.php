<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = (string) env('seed.superadmin.email', 'superadmin@mantenimiento.local');
        $password = (string) env('seed.superadmin.password', 'SuperAdmin1234');
        $now      = date('Y-m-d H:i:s');
        $existing = $this->db->table('usuarios')->where('email', $email)->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('usuarios')->where('id', $existing['id'])->update([
                'empresa_id'    => null,
                'es_superadmin' => 1,
                'activo'        => 1,
                'updated_at'    => $now,
                'deleted_at'    => null,
            ]);

            return;
        }

        $this->db->table('usuarios')->insert([
            'empresa_id'    => null,
            'nombre'        => 'Superadministrador',
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'es_superadmin' => 1,
            'activo'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
}
