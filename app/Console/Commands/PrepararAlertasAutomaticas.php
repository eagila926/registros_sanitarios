<?php

namespace App\Console\Commands;

use App\Models\Registro;
use App\Services\AlertasRegistros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PrepararAlertasAutomaticas extends Command
{
    protected $signature = 'registros:preparar-automaticas';

    protected $description =
        'Genera las alertas reales faltantes sin enviar correos';

    public function handle(AlertasRegistros $service): int
    {
        $lock = Cache::lock(
            'registros-alertas-envio-real',
            900
        );

        try {
            if (!$lock->get()) {
                $this->warn(
                    'Otro procesamiento de alertas está en curso.'
                );

                return self::FAILURE;
            }

            $registros = 0;
            $generadas = 0;
            $errores = 0;

            Registro::query()
                ->orderBy('id')
                ->chunkById(100, function ($items) use (
                    $service,
                    &$registros,
                    &$generadas,
                    &$errores
                ) {
                    foreach ($items as $registro) {
                        $registros++;

                        try {
                            $generadas += (int)
                                $service->generarReal(
                                    $registro->id
                                );
                        } catch (Throwable $e) {
                            $errores++;

                            $this->error(
                                'Registro '
                                . $registro->id
                                . ': '
                                . $e->getMessage()
                            );
                        }
                    }
                });

            $this->table(
                ['Concepto', 'Cantidad'],
                [
                    ['Registros revisados', $registros],
                    ['Alertas creadas', $generadas],
                    ['Errores', $errores],
                ]
            );

            if ($errores > 0) {
                return self::FAILURE;
            }

            $this->info(
                'Preparación automática completada.'
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error(
                'Error en preparación: ' . $e->getMessage()
            );

            return self::FAILURE;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                $this->error(
                    'No se pudo liberar el bloqueo: '
                    . $e->getMessage()
                );
            }
        }
    }
}
