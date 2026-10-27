<?php

namespace App\Console\Commands;

use App\Models\RegistroAlerta;
use Illuminate\Console\Command;

class RevisarAlertasBloqueadas extends Command
{
    protected $signature = 'registros:revisar-bloqueadas
        {--minutos=15 : Antigüedad mínima del bloqueo}';

    protected $description =
        'Identifica alertas interrumpidas sin modificar sus estados';

    public function handle(): int
    {
        $minutos = filter_var(
            $this->option('minutos'),
            FILTER_VALIDATE_INT
        );

        if ($minutos === false || $minutos < 1) {
            $this->error(
                'El número de minutos debe ser mayor que cero.'
            );

            return self::FAILURE;
        }

        $alertas = RegistroAlerta::query()
            ->where('estado', 'procesando')
            ->whereNotNull('bloqueada_at')
            ->where(
                'bloqueada_at',
                '<=',
                now()->subMinutes($minutos)
            )
            ->orderBy('bloqueada_at')
            ->get();

        if ($alertas->isEmpty()) {
            $this->info(
                'No existen alertas bloqueadas que requieran revisión.'
            );

            return self::SUCCESS;
        }

        $this->warn(
            'Se encontraron '
            . $alertas->count()
            . ' alertas que requieren revisión.'
        );

        $this->table(
            [
                'ID',
                'Registro',
                'Modo',
                'Tipo',
                'Intentos',
                'Bloqueada desde',
                'Último error'
            ],
            $alertas->map(fn ($alerta) => [
                $alerta->id,
                $alerta->registro_id,
                $alerta->modo,
                $alerta->tipo,
                $alerta->intentos,
                $alerta->bloqueada_at
                    ? $alerta->bloqueada_at->format('Y-m-d H:i:s')
                    : '',
                mb_strimwidth(
                    $alerta->ultimo_error ?? '',
                    0,
                    100,
                    '...'
                ),
            ])->all()
        );

        $this->warn(
            'No se modificaron estados ni se enviaron correos.'
        );

        return self::SUCCESS;
    }
}
