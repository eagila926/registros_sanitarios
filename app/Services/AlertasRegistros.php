<?php

namespace App\Services;

use App\Models\Registro;
use App\Models\RegistroAlerta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AlertasRegistros
{
    public const ZONA = 'America/Guayaquil';
    public const MAX_INTENTOS = 5;

    /*
    |--------------------------------------------------------------------------
    | CALCULAR DIAS RESTANTES
    |--------------------------------------------------------------------------
    */

    public function dias(Registro $r): int
    {
        $hoy = CarbonImmutable::today(self::ZONA);

        $vence = CarbonImmutable::parse(
            $r->fecha_vencimiento->format('Y-m-d'),
            self::ZONA
        );

        return (int) $hoy->diffInDays($vence, false);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERAR PLAN DE ALERTAS
    |--------------------------------------------------------------------------
    */

    public function plan(Registro $r): array
    {
        $dias = $this->dias($r);
        $plan = [];

        if ($dias < 0) {
            return [];
        }

        $vence = CarbonImmutable::parse(
            $r->fecha_vencimiento->format('Y-m-d'),
            self::ZONA
        );

        // Alerta inicial para registros próximos a vencer.
        if (
            $dias < 90 &&
            !in_array($dias, [60, 30], true)
        ) {
            $plan['inicial'] = now()
                ->format('Y-m-d H:i:s');
        }

        // Recordatorios a 90, 60 y 30 días.
        foreach ([90, 60, 30] as $umbral) {
            if ($dias >= $umbral) {
                $fecha = $vence
                    ->subDays($umbral)
                    ->setTime(8, 0)
                    ->setTimezone('UTC');

                $plan[$umbral . '_dias'] =
                    $fecha->format('Y-m-d H:i:s');
            }
        }

        return $plan;
    }

    /*
    |--------------------------------------------------------------------------
    | OBTENER DESTINATARIOS
    |--------------------------------------------------------------------------
    */

    private function destinatarios(Registro $r): array
    {
        $to = strtolower(
            trim($r->responsable?->email ?? '')
        );

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'El responsable no tiene un correo válido.'
            );
        }

        $cc = $r->copias()
            ->pluck('email')
            ->map(fn ($email) =>
                strtolower(trim($email))
            )
            ->filter()
            ->unique()
            ->reject(fn ($email) => $email === $to)
            ->values()
            ->all();

        foreach ($cc as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Existe un correo en copia inválido.'
                );
            }
        }

        return [$to, $cc];
    }

    /*
    |--------------------------------------------------------------------------
    | GENERAR ALERTAS POR MODO
    |--------------------------------------------------------------------------
    */

    private function generarPorModo(
        int $id,
        string $modo
    ): int {
        if (!in_array($modo, ['log', 'real'], true)) {
            throw new RuntimeException(
                'Modo de alerta no válido.'
            );
        }

        return DB::transaction(function () use ($id, $modo) {
            $registro = Registro::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$registro) {
                return 0;
            }

            // Cancelar pendientes de ciclos anteriores.
            $registro->alertas()
                ->where('modo', $modo)
                ->where(
                    'ciclo_alertas',
                    '!=',
                    $registro->ciclo_alertas
                )
                ->whereIn('estado', [
                    'pendiente',
                    'fallida'
                ])
                ->update([
                    'estado' => 'cancelada',
                    'proximo_intento_at' => null
                ]);

            // Cancelar alertas si el registro ya venció.
            if ($this->dias($registro) < 0) {
                $registro->alertas()
                    ->where('modo', $modo)
                    ->whereIn('estado', [
                        'pendiente',
                        'fallida'
                    ])
                    ->update([
                        'estado' => 'cancelada',
                        'proximo_intento_at' => null
                    ]);

                return 0;
            }

            $plan = $this->plan($registro);

            if (empty($plan)) {
                return 0;
            }

            $creadas = 0;
            $destinatarios = null;

            foreach ($plan as $tipo => $fecha) {
                $existe = $registro->alertas()
                    ->where(
                        'ciclo_alertas',
                        $registro->ciclo_alertas
                    )
                    ->where('tipo', $tipo)
                    ->where('modo', $modo)
                    ->exists();

                if ($existe) {
                    continue;
                }

                if ($destinatarios === null) {
                    $destinatarios =
                        $this->destinatarios($registro);
                }

                [$to, $cc] = $destinatarios;

                $alerta = new RegistroAlerta();

                $alerta->forceFill([
                    'registro_id' => $registro->id,
                    'ciclo_alertas' =>
                        $registro->ciclo_alertas,
                    'fecha_vencimiento' =>
                        $registro->fecha_vencimiento
                            ->format('Y-m-d'),
                    'tipo' => $tipo,
                    'programada_para' => $fecha,
                    'estado' => 'pendiente',
                    'destinatario_email' => $to,
                    'copias_emails' => $cc,
                    'intentos' => 0,
                    'modo' => $modo
                ])->save();

                $creadas++;
            }

            return $creadas;
        }, 3);
    }

    // Generar únicamente simulaciones.
    public function generar(int $id): int
    {
        return $this->generarPorModo($id, 'log');
    }

    // Generar únicamente alertas reales.
    public function generarReal(int $id): int
    {
        return $this->generarPorModo($id, 'real');
    }

    /*
    |--------------------------------------------------------------------------
    | CONSTRUIR CONTENIDO DE NOTIFICACION
    |--------------------------------------------------------------------------
    */

    private function contenido(
        Registro $r,
        RegistroAlerta $alerta,
        bool $simulacion = false
    ): array {
        $dias = $this->dias($r);

        $prefijo = $simulacion
            ? "[PRUEBA] "
            : "";

        $introduccion = $simulacion
            ? "SIMULACIÓN: no se ha enviado correo real.\n\n"
            : "";

        $contenido = $introduccion
            . "NOTIFICACIÓN DE VENCIMIENTO "
            . "DE REGISTRO SANITARIO\n\n"
            . "Estimado/a responsable:\n\n"
            . "Se informa que el siguiente registro "
            . "sanitario requiere seguimiento:\n\n"
            . "Producto: {$r->nombre_producto}\n"
            . "Fabricante: {$r->fabricante}\n"
            . "País: {$r->pais}\n"
            . "Registro RS/NSO: {$r->rs_nso}\n"
            . "Fecha de vencimiento: "
            . $r->fecha_vencimiento->format('d/m/Y')
            . "\n"
            . "Días restantes: {$dias}\n"
            . "Tipo de alerta: {$alerta->tipo}\n\n"
            . "Consultar registro:\n"
            . url('/registros/' . $r->id . '/editar')
            . "\n\n"
            . "Este mensaje fue generado automáticamente "
            . "por Registros Sanitarios de Escollanos.\n"
            . "El enlace requiere una cuenta autorizada.\n";

        $asunto = $prefijo
            . "Alerta de vencimiento de registro "
            . "sanitario - {$r->rs_nso}";

        return [
            'subject' => $asunto,
            'body' => $contenido
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RECLAMAR ALERTA PARA PROCESAMIENTO
    |--------------------------------------------------------------------------
    */

    private function reclamarModo(
        int $id,
        string $modo
    ): ?array {
        return DB::transaction(function () use ($id, $modo) {
            $r = Registro::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$r || $this->dias($r) < 0) {
                return null;
            }

            // No reclamar si existe un proceso activo.
            if ($r->alertas()
                ->where('modo', $modo)
                ->where('estado', 'procesando')
                ->exists()) {
                return null;
            }

            $base = $r->alertas()
                ->where('modo', $modo)
                ->where(
                    'ciclo_alertas',
                    $r->ciclo_alertas
                );

            // Seleccionar la alerta vencida más reciente.
            $due = (clone $base)
                ->whereIn('estado', [
                    'pendiente',
                    'fallida'
                ])
                ->where(
                    'programada_para',
                    '<=',
                    now()
                )
                ->orderByDesc('programada_para')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            if ($due->isEmpty()) {
                return null;
            }

            $alerta = $due->first();

            if (
                $alerta->intentos >= self::MAX_INTENTOS
            ) {
                return null;
            }

            if (
                $alerta->proximo_intento_at &&
                $alerta->proximo_intento_at->isFuture()
            ) {
                return null;
            }

            // No procesar otra alerta del mismo registro
            // y modo cuando ya existe una enviada hoy.
            $inicio = CarbonImmutable::today(
                self::ZONA
            )->setTimezone('UTC');

            $yaEnviada = (clone $base)
                ->where('estado', 'enviada')
                ->where('enviada_at', '>=', $inicio)
                ->exists();

            if ($yaEnviada) {
                return null;
            }

            // Comprobar destinatarios.
            [$to, $cc] = $this->destinatarios($r);

            $token = (string) Str::uuid();

            $alerta->update([
                'estado' => 'procesando',
                'destinatario_email' => $to,
                'copias_emails' => $cc,
                'intentos' => $alerta->intentos + 1,
                'ultimo_intento_at' => now(),
                'bloqueada_at' => now(),
                'bloqueo_token' => $token,
                'proximo_intento_at' => null,
                'ultimo_error' => null
            ]);

            $mensaje = $this->contenido(
                $r,
                $alerta,
                $modo === 'log'
            );

            return [
                'id' => $alerta->id,
                'token' => $token,
                'to' => $to,
                'cc' => $cc,
                'subject' => $mensaje['subject'],
                'body' => $mensaje['body'],
                'anteriores' => $due
                    ->skip(1)
                    ->pluck('id')
                    ->all()
            ];
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR ALERTA A ENVIADA
    |--------------------------------------------------------------------------
    */

    private function confirmarEnvio(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            $alerta = RegistroAlerta::whereKey(
                $payload['id']
            )
                ->where('estado', 'procesando')
                ->where(
                    'bloqueo_token',
                    $payload['token']
                )
                ->lockForUpdate()
                ->first();

            if (!$alerta) {
                throw new RuntimeException(
                    'No se pudo confirmar el estado de la alerta.'
                );
            }

            $alerta->update([
                'estado' => 'enviada',
                'enviada_at' => now(),
                'ultimo_error' => null,
                'proximo_intento_at' => null,
                'bloqueada_at' => null,
                'bloqueo_token' => null
            ]);

            // No acumular notificaciones atrasadas.
            if (!empty($payload['anteriores'])) {
                RegistroAlerta::whereIn(
                    'id',
                    $payload['anteriores']
                )
                    ->where('modo', $alerta->modo)
                    ->where('estado', 'pendiente')
                    ->update([
                        'estado' => 'cancelada',
                        'proximo_intento_at' => null
                    ]);
            }
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | SIMULACION MEDIANTE LOG
    |--------------------------------------------------------------------------
    */

    public function enviarSimulacion(int $id): string
    {
        $payload = $this->reclamarModo(
            $id,
            'log'
        );

        if (!$payload) {
            return 'omitida';
        }

        try {
            // Transporte fijo a log.
            config([
                'logging.channels.alertas_prueba' => [
                    'driver' => 'single',
                    'path' => storage_path(
                        'logs/alertas-registros.log'
                    ),
                    'level' => 'debug'
                ],
                'mail.mailers.alertas_prueba' => [
                    'transport' => 'log',
                    'channel' => 'alertas_prueba'
                ]
            ]);

            Mail::mailer('alertas_prueba')
                ->raw(
                    $payload['body'],
                    function ($message) use ($payload) {
                        $message
                            ->from(
                                'pruebas@escollanos.test',
                                'Registros Escollanos - pruebas'
                            )
                            ->to($payload['to'])
                            ->subject(
                                $payload['subject']
                            );

                        if (!empty($payload['cc'])) {
                            $message->cc(
                                $payload['cc']
                            );
                        }
                    }
                );

        } catch (Throwable $e) {
            $alerta = RegistroAlerta::whereKey(
                $payload['id']
            )
                ->where(
                    'bloqueo_token',
                    $payload['token']
                )
                ->first();

            if ($alerta) {
                $alerta->update([
                    'estado' => 'fallida',
                    'ultimo_error' => mb_substr(
                        $e->getMessage(),
                        0,
                        2000
                    ),
                    'proximo_intento_at' =>
                        $alerta->intentos < self::MAX_INTENTOS
                            ? now()->addMinutes(
                                5 * (2 ** ($alerta->intentos - 1))
                            )
                            : null,
                    'bloqueada_at' => null,
                    'bloqueo_token' => null
                ]);
            }

            return 'fallida';
        }

        $this->confirmarEnvio($payload);

        return 'simulada';
    }

    /*
    |--------------------------------------------------------------------------
    | ENVIO REAL MEDIANTE MICROSOFT GRAPH
    |--------------------------------------------------------------------------
    */

    public function enviarReal(
        int $id,
        MicrosoftGraphMailService $graph
    ): string {
        if (!config('microsoft_graph.enabled', false)) {
            throw new RuntimeException(
                'Microsoft Graph está desactivado.'
            );
        }

        $payload = $this->reclamarModo(
            $id,
            'real'
        );

        if (!$payload) {
            return 'omitida';
        }

        try {
            $graph->enviar(
                $payload['to'],
                $payload['subject'],
                $payload['body'],
                $payload['cc']
            );

        } catch (Throwable $e) {
            // Resultado potencialmente incierto.
            // No reintentar automáticamente.
            RegistroAlerta::whereKey(
                $payload['id']
            )
                ->where('estado', 'procesando')
                ->where(
                    'bloqueo_token',
                    $payload['token']
                )
                ->update([
                    'ultimo_error' => mb_substr(
                        'REVISAR ANTES DE REINTENTAR: '
                        . $e->getMessage(),
                        0,
                        2000
                    ),
                    'proximo_intento_at' => null
                ]);

            return 'revision';
        }

        // Graph aceptó la solicitud con HTTP 202.
        // La entrega final no está garantizada.
        $this->confirmarEnvio($payload);

        return 'enviada';
    }
}
