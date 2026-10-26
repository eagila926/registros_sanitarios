<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Simulaciones cada cinco minutos; las fechas se calculan en America/Guayaquil.
\Illuminate\Support\Facades\Schedule::command('registros:alertas --simular')
    ->everyFiveMinutes()->withoutOverlapping(30);
