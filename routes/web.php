<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\UsuarioController;

Route::get('/', function () {
    return redirect()->route(
        auth()->check() ? 'dashboard' : 'login'
    );
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')
        ->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('/registros/{registro}/editar', [\App\Http\Controllers\RegistroEdicionController::class, 'edit'])->name('registros.edit');
    Route::patch('/registros/{registro}', [\App\Http\Controllers\RegistroEdicionController::class, 'update'])->name('registros.update');

    Route::get('/registros', [RegistroController::class, 'index'])
        ->name('registros.index');

    Route::get('/usuarios', [UsuarioController::class, 'index'])
        ->name('usuarios.index');

    Route::patch('/usuarios/{usuario}', [UsuarioController::class, 'update'])
        ->name('usuarios.update');
});

require __DIR__.'/auth.php';