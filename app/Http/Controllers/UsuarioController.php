<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        Gate::authorize('administrar-usuarios');

        $usuarios = User::withCount('registros')
            ->orderBy('name')
            ->get();

        return view('usuarios.index', compact('usuarios'));
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        Gate::authorize('administrar-usuarios');

        // Esta pantalla administra responsables.
        // Las cuentas administrativas no se modifican aquí.
        abort_unless($usuario->role === 'responsable', 403);

        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
            'password' => [
                'nullable',
                'string',
                'confirmed',
                Password::min(12),
            ],
        ]);

        // Al activar una cuenta desactivada se exige asignar contraseña.
        if (
            ! $usuario->activo
            && $request->boolean('activo')
            && empty($datos['password'])
        ) {
            return back()->withErrors([
                'password' => 'Asigna una contraseña para activar esta cuenta.',
            ]);
        }

        $usuario->activo = $request->boolean('activo');

        if (! empty($datos['password'])) {
            $usuario->password = Hash::make($datos['password']);
            $usuario->remember_token = Str::random(60);
        }

        if (! $usuario->activo) {
            $usuario->remember_token = Str::random(60);
        }

        $usuario->save();

        return redirect()
            ->route('usuarios.index')
            ->with('status', 'Usuario actualizado: '.$usuario->name);
    }
}