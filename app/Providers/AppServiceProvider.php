<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\Registro::class,
            \App\Policies\RegistroPolicy::class
        );

        \Illuminate\Support\Facades\Gate::define(
            'administrar-usuarios',
            fn (\App\Models\User $user) =>
                $user->activo && $user->esAdministrador()
        );
    }
}
