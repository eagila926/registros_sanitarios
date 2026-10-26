<?php
namespace App\Console\Commands;
use App\Models\Registro;
use App\Models\RegistroAlerta;
use App\Services\AlertasRegistros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
class ProcesarAlertasRegistros extends Command {
    protected $signature='registros:alertas {--simular : Generar alertas y escribir correos solo en el log}';
    protected $description='Vista previa por defecto; --simular registra avisos sin enviar correos reales.';
    public function handle(AlertasRegistros $service): int {
        if (!Schema::hasColumn('registro_alertas','modo')) {$this->error('Ejecuta php artisan migrate antes de usar este comando.');return self::FAILURE;}
        $stats=['registros'=>0,'vencidos'=>0,'hasta_90_dias'=>0,'alertas_creadas'=>0,'simuladas'=>0,'fallidas'=>0];
        Registro::orderBy('id')->chunkById(100,function ($rows) use ($service,&$stats) {
            foreach ($rows as $r) {
                $stats['registros']++;$dias=$service->dias($r);
                if ($dias<0) $stats['vencidos']++; elseif ($dias<=90) $stats['hasta_90_dias']++;
                if (!$this->option('simular')) continue;
                try {
                    $stats['alertas_creadas']+=$service->generar($r->id);
                    $status=$service->enviarSimulacion($r->id);
                    if ($status==='simulada') $stats['simuladas']++;
                    if ($status==='fallida') $stats['fallidas']++;
                } catch (\Throwable $e) {$stats['fallidas']++;$this->error('Registro '.$r->id.': '.$e->getMessage());}
            }
        });
        $this->info($this->option('simular') ? 'SIMULACION: 0 correos reales enviados.' : 'VISTA PREVIA: no se modificaron datos ni se escribieron correos.');
        $this->table(['Concepto','Cantidad'],collect($stats)->map(fn($v,$k)=>[$k,$v])->values()->all());
        $this->table(['Estado (modo log)','Cantidad'],RegistroAlerta::where('modo','log')->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->get()->map(fn($r)=>[$r->estado,$r->total])->all());
        $stuck=RegistroAlerta::where('modo','log')->where('estado','procesando')->where('bloqueada_at','<',now()->subMinutes(15))->pluck('id');
        if ($stuck->isNotEmpty()) $this->warn('Revisar avisos interrumpidos antes de reintentar: '.$stuck->implode(', '));
        return $stats['fallidas'] ? self::FAILURE : self::SUCCESS;
    }
}
