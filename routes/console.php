<?php

use Illuminate\Support\Facades\Schedule;

// Preparar las alertas todos los días a las 07:55 Ecuador.
Schedule::command('registros:preparar-automaticas')
    ->dailyAt('07:55')
    ->timezone('America/Guayaquil')
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/registros-preparacion.log'));

// Enviar hasta cinco correos cada cinco minutos,
// únicamente entre 08:00 y 08:55 Ecuador.
Schedule::command('registros:alertas --enviar --limite=5')
    ->everyFiveMinutes()
    ->timezone('America/Guayaquil')
    ->between('08:00', '08:55')
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/registros-envios.log'));
