<?php
namespace App\Services;

use App\Models\Registro;
use App\Models\RegistroAlerta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AlertasRegistros
{
    public const ZONA = 'America/Guayaquil';
    public const MAX_INTENTOS = 5;

    public function dias(Registro $r): int {
        return (int) CarbonImmutable::today(self::ZONA)->diffInDays(CarbonImmutable::parse($r->fecha_vencimiento->format('Y-m-d'),self::ZONA),false);
    }

    
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

        if ($dias < 90 && !in_array($dias, [60, 30], true)) {
            $plan['inicial'] = now()->format('Y-m-d H:i:s');
        }

        foreach ([90, 60, 30] as $umbral) {
            if ($dias >= $umbral) {
                $fecha = $vence
                    ->subDays($umbral)
                    ->setTime(8, 0)
                    ->setTimezone(config('app.timezone'));

                $plan[$umbral . '_dias'] = $fecha->format('Y-m-d H:i:s');
            }
        }

        return $plan;
    }


    private function destinatarios(Registro $r): array {
        $to=strtolower(trim($r->responsable?->email ?? ''));
        if (!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('El responsable no tiene un correo valido.');
        // La desactivacion del login no cambia la responsabilidad del registro.
        $cc=$r->copias()->pluck('email')->map(fn($s)=>strtolower(trim($s)))->filter()->unique()->reject(fn($s)=>$s===$to)->values()->all();
        foreach ($cc as $email) if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Hay un correo en copia invalido.');
        return [$to,$cc];
    }

    
    public function generar(int $id): int
    {
        return DB::transaction(function () use ($id) {

            $r = Registro::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$r) {
                return 0;
            }

            // Cancelar alertas pendientes de ciclos anteriores.
            $r->alertas()
                ->where('modo', 'log')
                ->where('ciclo_alertas', '!=', $r->ciclo_alertas)
                ->whereIn('estado', ['pendiente', 'fallida'])
                ->update([
                    'estado' => 'cancelada',
                    'proximo_intento_at' => null
                ]);

            // No generar alertas para registros vencidos.
            if ($this->dias($r) < 0) {
                $r->alertas()
                    ->where('modo', 'log')
                    ->whereIn('estado', ['pendiente', 'fallida'])
                    ->update([
                        'estado' => 'cancelada',
                        'proximo_intento_at' => null
                    ]);

                return 0;
            }

            $plan = $this->plan($r);

            if (empty($plan)) {
                return 0;
            }

            $count = 0;
            $destinatarios = null;

            foreach ($plan as $tipo => $fecha) {

                // Verificar cada alerta individualmente.
                $existe = $r->alertas()
                    ->where('ciclo_alertas', $r->ciclo_alertas)
                    ->where('modo', 'log')
                    ->where('tipo', $tipo)
                    ->exists();

                if ($existe) {
                    continue;
                }

                // Solo validar destinatarios si hay algo que crear.
                if ($destinatarios === null) {
                    $destinatarios = $this->destinatarios($r);
                }

                [$to, $cc] = $destinatarios;

                $alerta = new RegistroAlerta();

                $alerta->forceFill([
                    'registro_id' => $r->id,
                    'ciclo_alertas' => $r->ciclo_alertas,
                    'fecha_vencimiento' => $r->fecha_vencimiento->format('Y-m-d'),
                    'tipo' => $tipo,
                    'programada_para' => $fecha,
                    'estado' => 'pendiente',
                    'destinatario_email' => $to,
                    'copias_emails' => $cc,
                    'modo' => 'log'
                ])->save();

                $count++;
            }

            return $count;

        }, 3);
    }


    
    private function reclamar(int $id): ?array
    {
        return DB::transaction(function () use ($id) {

            $r = Registro::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$r || $this->dias($r) < 0) {
                return null;
            }

            // Evitar procesamientos simultáneos del mismo registro.
            if ($r->alertas()
                ->where('modo', 'log')
                ->where('estado', 'procesando')
                ->exists()) {
                return null;
            }

            $base = $r->alertas()
                ->where('modo', 'log')
                ->where('ciclo_alertas', $r->ciclo_alertas);

            // Obtener alertas cuya fecha de programación ya llegó.
            // Priorizar la más reciente para no acumular avisos atrasados.
            $due = (clone $base)
                ->whereIn('estado', ['pendiente', 'fallida'])
                ->where('programada_para', '<=', now())
                ->orderByDesc('programada_para')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            if ($due->isEmpty()) {
                return null;
            }

            $a = $due->first();

            // No superar el máximo de intentos permitido.
            if ($a->intentos >= self::MAX_INTENTOS) {
                return null;
            }

            // Respetar el tiempo de espera entre reintentos.
            if (
                $a->proximo_intento_at &&
                $a->proximo_intento_at->isFuture()
            ) {
                return null;
            }

            // Evitar más de una notificación registrada como
            // enviada durante el mismo día.
            $inicio = CarbonImmutable::today(self::ZONA)
                ->setTimezone(config('app.timezone'));

            $yaEnviadaHoy = (clone $base)
                ->where('estado', 'enviada')
                ->where('enviada_at', '>=', $inicio)
                ->exists();

            if ($yaEnviadaHoy) {
                return null;
            }

            // Validar destinatarios antes de modificar alertas.
            [$to, $cc] = $this->destinatarios($r);

            // Cancelar avisos anteriores únicamente cuando
            // la alerta seleccionada está lista para procesarse.
            foreach ($due->skip(1) as $old) {
                $old->update([
                    'estado' => 'cancelada',
                    'proximo_intento_at' => null
                ]);
            }

            // Crear identificador único del procesamiento.
            $token = (string) Str::uuid();

            // Marcar la alerta como en procesamiento.
            $a->update([
                'estado' => 'procesando',
                'destinatario_email' => $to,
                'copias_emails' => $cc,
                'intentos' => $a->intentos + 1,
                'ultimo_intento_at' => now(),
                'bloqueada_at' => now(),
                'bloqueo_token' => $token,
                'proximo_intento_at' => null
            ]);

            // Calcular días restantes del registro sanitario.
            $dias = $this->dias($r);

            // Construir el contenido de la notificación simulada.
            $text = "SIMULACION: no se ha enviado correo real.\n\n"
                . "Registro sanitario proximo a vencer\n"
                . "Producto: {$r->nombre_producto}\n"
                . "Fabricante: {$r->fabricante}\n"
                . "Pais: {$r->pais}\n"
                . "RS/NSO: {$r->rs_nso}\n"
                . "Vencimiento: "
                . $r->fecha_vencimiento->format('d/m/Y')
                . "\n"
                . "Dias restantes: {$dias}\n"
                . "Tipo de aviso: {$a->tipo}\n\n"
                . "Revisar: "
                . url('/registros/' . $r->id . '/editar')
                . "\n"
                . "El enlace requiere una cuenta con permiso "
                . "sobre el registro.\n";

            // Devolver los datos necesarios para la simulación.
            return [
                'id' => $a->id,
                'token' => $token,
                'to' => $to,
                'cc' => $cc,
                'body' => $text,
                'subject' => '[PRUEBA] Vencimiento de registro sanitario - '
                    . $r->rs_nso
            ];

        }, 3);
    }


    public function enviarSimulacion(int $id): string {
        $payload=$this->reclamar($id);
        if (!$payload) return 'omitida';
        try {
            // Transporte fijo a log: incluso con MAIL_MAILER=smtp, este modulo no envia correo real.
            config(['logging.channels.alertas_prueba'=>['driver'=>'single','path'=>storage_path('logs/alertas-registros.log'),'level'=>'debug'],
                'mail.mailers.alertas_prueba'=>['transport'=>'log','channel'=>'alertas_prueba']]);
            Mail::mailer('alertas_prueba')->raw($payload['body'],function ($message) use ($payload) {
                $message->from('pruebas@escollanos.test','Registros Escollanos - pruebas')->to($payload['to'])->subject($payload['subject']);
                if ($payload['cc']) $message->cc($payload['cc']);
            });
        } catch (\Throwable $e) {
            $a=RegistroAlerta::whereKey($payload['id'])->where('bloqueo_token',$payload['token'])->first();
            if ($a) $a->update(['estado'=>'fallida','ultimo_error'=>mb_substr($e->getMessage(),0,2000),'proximo_intento_at'=>$a->intentos<self::MAX_INTENTOS ? now()->addMinutes(5*(2**($a->intentos-1))) : null,'bloqueada_at'=>null,'bloqueo_token'=>null]);
            return 'fallida';
        }
        // Si el proceso cae despues de escribir el log, permanece procesando: no se reintenta a ciegas.
        RegistroAlerta::whereKey($payload['id'])->where('estado','procesando')->where('bloqueo_token',$payload['token'])->update(['estado'=>'enviada','enviada_at'=>now(),'ultimo_error'=>null,'proximo_intento_at'=>null,'bloqueada_at'=>null,'bloqueo_token'=>null]);
        return 'simulada';
    }
}
