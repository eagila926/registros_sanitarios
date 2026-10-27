<?php

namespace App\Console\Commands;

use App\Models\Registro;
use App\Models\RegistroAlerta;
use App\Services\AlertasRegistros;
use Illuminate\Console\Command;

class PrepararAlertasReales extends Command
{
    protected $signature = 'registros:preparar-reales
                            {--confirmar : Confirmar la creación de alertas reales}';

    protected $description =
        'Prepara alertas reales sin enviar correos electrónicos';

    public function handle(AlertasRegistros $service): int
    {
        if (!$this->option('confirmar')) {
            $this->warn(
                'No se modificaron datos. Agrega --confirmar para preparar.'
            );

            return self::SUCCESS;
        }

        $registros = 0;
        $creadas = 0;
        $errores = 0;

        Registro::query()
            ->orderBy('id')
            ->chunkById(100, function ($items) use (
                $service,
                &$registros,
                &$creadas,
                &$errores
            ) {
                foreach ($items as $registro) {

                    $registros++;

                    try {
                        $creadas += $service->generarReal(
                            $registro->id
                        );
                    } catch (\Throwable $e) {

                        $errores++;

                        $this->error(
                            "Registro {$registro->id}: "
                            . $e->getMessage()
                        );
                    }
                }
            });

        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Registros revisados', $registros],
                ['Alertas reales creadas', $creadas],
                ['Errores', $errores],
                [
                    'Total alertas reales',
                    RegistroAlerta::where('modo', 'real')->count()
                ]
            ]
        );

        $this->info('No se enviaron correos electrónicos.');

        return $errores > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
