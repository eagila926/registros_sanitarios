<?php

namespace App\Policies;

use App\Models\Registro;
use App\Models\User;

class RegistroPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->activo;
    }

    public function view(User $user, Registro $registro): bool
    {
        return $user->activo && (
            $user->esAdministrador()
            || (int) $registro->responsable_id === (int) $user->id
        );
    }

    public function update(User $user, Registro $registro): bool
    {
        return $this->view($user, $registro);
    }
}