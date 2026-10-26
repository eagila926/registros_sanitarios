<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'elvis.agila@escollanos.com.ec';

        if (User::where('email', $email)->exists()) {
            $this->command->warn(
                'El usuario ya existe. No se modificaron sus datos.'
            );

            return;
        }

        $password = $this->command->secret(
            'Ingresa una contraseña de al menos 12 caracteres'
        );

        if (! is_string($password) || mb_strlen($password) < 12) {
            $this->command->error(
                'La contraseña debe tener al menos 12 caracteres.'
            );

            return;
        }

        $confirmation = $this->command->secret(
            'Confirma la contraseña'
        );

        if ($password !== $confirmation) {
            $this->command->error('Las contraseñas no coinciden.');

            return;
        }

        $user = new User();
        $user->name = 'Elvis Agila';
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->role = 'administrador';
        $user->activo = true;
        $user->save();

        $this->command->info(
            'Administrador creado: '.$email
        );
    }
}