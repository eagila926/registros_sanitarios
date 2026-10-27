<?php

namespace App\Console\Commands;

use App\Models\Registro;
use App\Models\RegistroAlerta;
use App\Services\AlertasRegistros;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcesarAlertasRegistros extends Command
{
    protected $signature = 'registros:alertas
        {--simular : Procesar simulaciones en el log}
        {--enviar : Enviar alertas reales mediante Microsoft Graph}
        {--limite=1 : Máximo de envíos reales por ejecución (1 a 10)}';

    protected $description =
        'Vista previa, simulación y envío controlado de alertas';

    public function handle(
        AlertasRegistros $service,
        MicrosoftGraphMailService $graph
    ): int {

        /*
        |--------------------------------------------------------------------------
        | VALIDACIONES INICIALES
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasColumn('registro_alertas', 'modo')) {
            $this->error(
                'La base de datos no está actualizada.'
            );

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $enviar = (bool) $this->option('enviar');

        if ($simular && $enviar) {
            $this->error(
                'No puedes combinar --simular y --enviar.'
            );

            return self::FAILURE;
        }

        $limiteTexto = (string) $this->option('limite');

        if (
            !ctype_digit($limiteTexto) ||
            (int) $limiteTexto < 1 ||
            (int) $limiteTexto > 10
        ) {
            $this->error(
                'El límite debe ser un número entre 1 y 10.'
            );

            return self::FAILURE;
        }

        $limite = (int) $limiteTexto;

        if (
            $enviar &&
            !config('microsoft_graph.enabled', false)
        ) {
            $this->error(
                'Microsoft Graph está desactivado.'
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | ESTADÍSTICAS
        |--------------------------------------------------------------------------
        */

        $stats = [
            'registros' => 0,
            'vencidos' => 0,
            'hasta_90_dias' => 0,
            'alertas_creadas' => 0,
            'simuladas' => 0,
            'enviadas_reales' => 0,
            'revision' => 0,
            'fallidas' => 0,
        ];

        $intentosReales = 0;

        /*
        |--------------------------------------------------------------------------
        | BLOQUEO GLOBAL DE ENVÍOS REALES
        |--------------------------------------------------------------------------
        |
        | Impide que dos ejecuciones de este comando
        | envíen alertas reales simultáneamente.
        |
        | Se utiliza la caché compartida de Laravel.
        | El bloqueo expira después de 15 minutos.
        |
        */

        $lock = null;

        if ($enviar) {
            try {
                $lock = Cache::lock(
                    'registros-alertas-envio-real',
                    900
                );

                if (!$lock->get()) {
                    $this->warn(
                        'Ya existe otra ejecución de '
                        . 'envío real en curso.'
                    );

                    return self::FAILURE;
                }

            } catch (Throwable $e) {
                $this->error(
                    'No se pudo adquirir el bloqueo global: '
                    . $e->getMessage()
                );

                return self::FAILURE;
            }

            $this->info(
                'Bloqueo global adquirido correctamente.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PROCESAMIENTO PRINCIPAL
        |--------------------------------------------------------------------------
        */

        try {

            Registro::query()
                ->orderBy('id')
                ->chunkById(
                    100,
                    function ($registros) use (
                        $service,
                        $graph,
                        $simular,
                        $enviar,
                        $limite,
                        &$intentosReales,
                        &$stats
                    ) {

                        foreach ($registros as $registro) {

                            // Detener procesamiento al alcanzar
                            // el límite de envíos reales.
                            if (
                                $enviar &&
                                $intentosReales >= $limite
                            ) {
                                return false;
                            }

                            $stats['registros']++;

                            try {

                                /*
                                |------------------------------------------
                                | CALCULAR VENCIMIENTOS
                                |------------------------------------------
                                */

                                $dias = $service->dias($registro);

                                if ($dias < 0) {
                                    $stats['vencidos']++;
                                } elseif ($dias <= 90) {
                                    $stats['hasta_90_dias']++;
                                }

                                /*
                                |------------------------------------------
                                | MODO VISTA PREVIA
                                |------------------------------------------
                                */

                                if (!$simular && !$enviar) {
                                    continue;
                                }

                                /*
                                |------------------------------------------
                                | MODO SIMULACIÓN
                                |------------------------------------------
                                */

                                if ($simular) {

                                    $stats['alertas_creadas'] +=
                                        $service->generar(
                                            $registro->id
                                        );

                                    $estado =
                                        $service->enviarSimulacion(
                                            $registro->id
                                        );

                                    if ($estado === 'simulada') {
                                        $stats['simuladas']++;
                                    } elseif (
                                        $estado === 'fallida'
                                    ) {
                                        $stats['fallidas']++;
                                    }

                                    continue;
                                }

                                /*
                                |------------------------------------------
                                | MODO ENVÍO REAL
                                |------------------------------------------
                                |
                                | Procesa únicamente alertas reales
                                | previamente generadas.
                                |
                                | No genera registros nuevos durante
                                | el procesamiento de correos.
                                |
                                */

                                if ($enviar) {

                                    $estado = $service->enviarReal(
                                        $registro->id,
                                        $graph
                                    );

                                    switch ($estado) {

                                        case 'enviada':

                                            $intentosReales++;

                                            $stats['enviadas_reales']++;

                                            $this->info(
                                                'Microsoft Graph aceptó '
                                                . 'la solicitud del registro '
                                                . $registro->id
                                            );

                                            break;

                                        case 'revision':

                                            $intentosReales++;

                                            $stats['revision']++;

                                            $this->warn(
                                                'Registro '
                                                . $registro->id
                                                . ': envío con resultado '
                                                . 'incierto. Requiere revisión.'
                                            );

                                            // Detener el lote para evitar
                                            // procesamientos adicionales.
                                            return false;

                                        case 'omitida':

                                            // Sin alertas elegibles.
                                            // No consume el límite.
                                            break;

                                        default:

                                            $stats['fallidas']++;

                                            $this->error(
                                                'Estado inesperado en '
                                                . 'registro '
                                                . $registro->id
                                                . ': '
                                                . $estado
                                            );

                                            return false;
                                    }
                                }

                            } catch (Throwable $e) {

                                $stats['fallidas']++;

                                $this->error(
                                    'Error en registro '
                                    . $registro->id
                                    . ': '
                                    . $e->getMessage()
                                );

                                // En envío real, detener todo
                                // el lote ante cualquier excepción.
                                if ($enviar) {
                                    return false;
                                }
                            }
                        }
                    }
                );

            /*
            |--------------------------------------------------------------------------
            | RESUMEN DE EJECUCIÓN
            |--------------------------------------------------------------------------
            */

            if ($enviar) {
                $this->info(
                    'Procesamiento de correos reales finalizado.'
                );

            } elseif ($simular) {
                $this->info(
                    'SIMULACIÓN: no se enviaron correos reales.'
                );

            } else {
                $this->info(
                    'VISTA PREVIA: no se modificaron alertas.'
                );
            }

            $this->table(
                ['Concepto', 'Cantidad'],
                collect($stats)
                    ->map(
                        fn ($valor, $clave) => [
                            $clave,
                            $valor
                        ]
                    )
                    ->values()
                    ->all()
            );

            if ($enviar) {
                $this->info(
                    'Solicitudes contabilizadas: '
                    . $intentosReales
                    . ' / '
                    . $limite
                );
            }

            /*
            |--------------------------------------------------------------------------
            | DETECTAR ALERTAS BLOQUEADAS
            |--------------------------------------------------------------------------
            |
            | Solo se muestran para revisión.
            | No se desbloquean automáticamente.
            |
            */

            $bloqueadas = RegistroAlerta::query()
                ->where('estado', 'procesando')
                ->where(
                    'bloqueada_at',
                    '<',
                    now()->subMinutes(15)
                )
                ->orderBy('id')
                ->get([
                    'id',
                    'registro_id',
                    'modo',
                    'intentos',
                    'bloqueada_at',
                    'ultimo_error'
                ]);

            if ($bloqueadas->isNotEmpty()) {

                $this->warn(
                    'ALERTAS BLOQUEADAS: '
                    . 'requieren revisión manual.'
                );

                $this->table(
                    [
                        'Alerta',
                        'Registro',
                        'Modo',
                        'Intentos',
                        'Bloqueada desde'
                    ],
                    $bloqueadas
                        ->map(
                            fn ($alerta) => [
                                $alerta->id,
                                $alerta->registro_id,
                                $alerta->modo,
                                $alerta->intentos,
                                $alerta->bloqueada_at
                            ]
                        )
                        ->all()
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RESULTADO FINAL
            |--------------------------------------------------------------------------
            */

            if (
                $stats['fallidas'] > 0 ||
                $stats['revision'] > 0
            ) {
                return self::FAILURE;
            }

            return self::SUCCESS;

        } catch (Throwable $e) {

            $this->error(
                'Error general en procesamiento: '
                . $e->getMessage()
            );

            return self::FAILURE;

        } finally {

            /*
            |--------------------------------------------------------------------------
            | LIBERAR BLOQUEO GLOBAL
            |--------------------------------------------------------------------------
            |
            | Se ejecuta incluso si el procesamiento
            | termina con un error o una excepción.
            |
            */

            if ($lock !== null) {

                try {
                    $lock->release();

                    $this->info(
                        'Bloqueo global liberado.'
                    );

                } catch (Throwable $e) {

                    $this->error(
                        'Error liberando bloqueo: '
                        . $e->getMessage()
                    );
                }
            }
        }
    }
}
