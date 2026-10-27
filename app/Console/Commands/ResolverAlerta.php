<?php

namespace App\Console\Commands;

use App\Models\Registro;
use App\Models\RegistroAlerta;
use App\Services\AlertasRegistros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ResolverAlerta extends Command
{
    protected $signature = 'registros:resolver-alerta
        {--listar : Mostrar alertas reales en procesamiento}
        {--confirmar= : Confirmar una solicitud aceptada por Graph}
        {--cancelar= : Cancelar una alerta revisada}
        {--reintentar= : Autorizar un nuevo intento}
        {--operador= : Correo corporativo del administrador}
        {--evidencia= : Referencia o descripción de la verificación}
        {--verificado-no-enviado : Confirmación de que se comprobó la ausencia de envío}
        {--aplicar : Autorizar la modificación de la base de datos}';

    protected $description =
        'Resolución manual y auditada de alertas reales interrumpidas';

    public function handle(): int
    {
        $acciones = [
            'confirmar' => $this->option('confirmar'),
            'cancelar' => $this->option('cancelar'),
            'reintentar' => $this->option('reintentar'),
        ];

        $seleccionadas = array_filter(
            $acciones,
            fn ($valor) => $valor !== null
        );

        if ($this->option('listar') && count($seleccionadas) > 0) {
            $this->error('No combines --listar con modificaciones.');
            return self::FAILURE;
        }

        if (count($seleccionadas) === 0) {
            return $this->listar();
        }

        if (count($seleccionadas) !== 1) {
            $this->error('Selecciona exactamente una operación.');
            return self::FAILURE;
        }

        $accion = array_key_first($seleccionadas);
        $idTexto = (string) $seleccionadas[$accion];

        if (
            !ctype_digit($idTexto) ||
            (int) $idTexto < 1
        ) {
            $this->error('El ID de alerta no es válido.');
            return self::FAILURE;
        }

        $id = (int) $idTexto;

        $operador = strtolower(trim(
            (string) $this->option('operador')
        ));

        $evidencia = trim(
            (string) $this->option('evidencia')
        );

        if (!filter_var($operador, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indica un correo válido en --operador.');
            return self::FAILURE;
        }

        if (
            mb_strlen($operador) > 150 ||
            mb_strlen($evidencia) < 15 ||
            mb_strlen($evidencia) > 5000
        ) {
            $this->error(
                'El operador debe tener hasta 150 caracteres y '
                . 'la evidencia entre 15 y 5000 caracteres.'
            );
            return self::FAILURE;
        }

        if (
            $accion === 'reintentar' &&
            !$this->option('verificado-no-enviado')
        ) {
            $this->error(
                'Un reintento requiere --verificado-no-enviado.'
            );
            return self::FAILURE;
        }

        if (!$this->option('aplicar')) {
            $this->warn('VISTA PREVIA: no se modificaron datos.');
            $this->line("Acción: {$accion}");
            $this->line("Alerta: {$id}");
            $this->line("Operador: {$operador}");
            $this->line("Evidencia: {$evidencia}");
            $this->warn('Usa --aplicar para confirmar.');
            return self::SUCCESS;
        }

        // El mismo bloqueo utilizado por el envío real.
        $lock = Cache::lock(
            'registros-alertas-envio-real',
            900
        );

        try {
            if (!$lock->get()) {
                $this->error(
                    'Existe un procesamiento de envíos reales activo.'
                );
                return self::FAILURE;
            }

            // Obtener el ID del registro antes de bloquear las filas.
            $alertaBase = RegistroAlerta::query()
                ->whereKey($id)
                ->where('modo', 'real')
                ->first(['id', 'registro_id']);

            if (!$alertaBase) {
                throw new RuntimeException(
                    'No existe la alerta real indicada.'
                );
            }

            DB::transaction(function () use (
                $id,
                $accion,
                $operador,
                $evidencia,
                $alertaBase
            ) {
                // Mantener el mismo orden de bloqueos
                // utilizado por AlertasRegistros.
                $registro = Registro::query()
                    ->whereKey($alertaBase->registro_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alerta = RegistroAlerta::query()
                    ->whereKey($id)
                    ->where('registro_id', $registro->id)
                    ->where('modo', 'real')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($alerta->estado !== 'procesando') {
                    throw new RuntimeException(
                        'Solo pueden resolverse alertas '
                        . 'reales en estado procesando.'
                    );
                }

                if (
                    !$alerta->bloqueada_at ||
                    $alerta->bloqueada_at->greaterThan(
                        now()->subMinutes(15)
                    )
                ) {
                    throw new RuntimeException(
                        'El bloqueo todavía no supera 15 minutos.'
                    );
                }

                $anterior = $alerta->estado;

                if ($accion === 'confirmar') {
                    $alerta->estado = 'enviada';
                    $alerta->enviada_at = now();
                    $alerta->ultimo_error = null;
                    $alerta->proximo_intento_at = null;

                    // Evitar acumular avisos anteriores
                    // del mismo registro y ciclo.
                    $registro->alertas()
                        ->where('modo', 'real')
                        ->where(
                            'ciclo_alertas',
                            $alerta->ciclo_alertas
                        )
                        ->where('id', '!=', $alerta->id)
                        ->whereIn('estado', [
                            'pendiente',
                            'fallida'
                        ])
                        ->where(
                            'programada_para',
                            '<=',
                            $alerta->programada_para
                        )
                        ->update([
                            'estado' => 'cancelada',
                            'proximo_intento_at' => null,
                        ]);
                }

                if ($accion === 'cancelar') {
                    $alerta->estado = 'cancelada';
                    $alerta->proximo_intento_at = null;
                    $alerta->ultimo_error =
                        'Cancelación administrativa. '
                        . 'Consultar auditoría.';
                }

                if ($accion === 'reintentar') {
                    $dias = app(AlertasRegistros::class)
                        ->dias($registro);

                    if (
                        $dias < 0 ||
                        $alerta->ciclo_alertas !==
                            $registro->ciclo_alertas
                    ) {
                        throw new RuntimeException(
                            'El registro está vencido o '
                            . 'pertenece a otro ciclo.'
                        );
                    }

                    if (
                        $alerta->intentos >=
                        AlertasRegistros::MAX_INTENTOS
                    ) {
                        throw new RuntimeException(
                            'La alerta superó el límite de intentos.'
                        );
                    }

                    $alerta->estado = 'pendiente';
                    $alerta->proximo_intento_at = null;
                    $alerta->ultimo_error =
                        'Reintento autorizado manualmente. '
                        . 'Consultar auditoría.';
                }

                $alerta->bloqueada_at = null;
                $alerta->bloqueo_token = null;
                $alerta->save();

                DB::table('registro_alerta_resoluciones')
                    ->insert([
                        'registro_alerta_id' => $alerta->id,
                        'operador' => $operador,
                        'accion' => $accion,
                        'estado_anterior' => $anterior,
                        'estado_nuevo' => $alerta->estado,
                        'evidencia' => $evidencia,
                        'created_at' => now(),
                    ]);
            }, 3);

            $this->info(
                "Alerta {$id}: resolución {$accion} registrada."
            );

            $this->info(
                'No se enviaron correos electrónicos.'
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error(
                'No se completó la resolución: '
                . $e->getMessage()
            );

            return self::FAILURE;

        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                $this->warn(
                    'No se pudo liberar el bloqueo: '
                    . $e->getMessage()
                );
            }
        }
    }

    private function listar(): int
    {
        $alertas = RegistroAlerta::query()
            ->where('modo', 'real')
            ->where('estado', 'procesando')
            ->orderBy('bloqueada_at')
            ->get();

        if ($alertas->isEmpty()) {
            $this->info(
                'No existen alertas reales en procesamiento.'
            );
            return self::SUCCESS;
        }

        $this->table(
            [
                'ID',
                'Registro',
                'Tipo',
                'Intentos',
                'Bloqueada desde',
                'Revisar'
            ],
            $alertas->map(function ($a) {
                $antigua = $a->bloqueada_at &&
                    $a->bloqueada_at->lessThanOrEqualTo(
                        now()->subMinutes(15)
                    );

                return [
                    $a->id,
                    $a->registro_id,
                    $a->tipo,
                    $a->intentos,
                    $a->bloqueada_at
                        ? $a->bloqueada_at->format('Y-m-d H:i:s')
                        : 'Sin fecha',
                    $antigua ? 'SÍ' : 'AÚN NO',
                ];
            })->all()
        );

        return self::SUCCESS;
    }
}
