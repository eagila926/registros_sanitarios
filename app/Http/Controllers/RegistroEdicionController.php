<?php
namespace App\Http\Controllers;

use App\Models\Registro;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistroEdicionController extends Controller
{
    public const CAMPOS = [
        'pais'=>'Pais', 'fabricante'=>'Fabricante', 'nombre_producto'=>'Nombre del producto',
        'rs_nso'=>'RS/NSO', 'fecha_vencimiento'=>'Fecha de vencimiento', 'grupo'=>'Grupo',
        'forma_farmaceutica'=>'Forma farmaceutica', 'presentacion'=>'Presentacion',
        'estado_actual'=>'Estado informado', 'tipo_elemento'=>'Tipo de elemento', 'ruta_origen'=>'Ruta de origen',
    ];
    public const COPIAS = ['responsable2'=>'Responsable 2','auditor'=>'Auditor','observador1'=>'Observador 1','observador2'=>'Observador 2','observador3'=>'Observador 3'];

    public static function snapshot(Registro $registro): array
    {
        $data = $registro->only(array_keys(self::CAMPOS));
        $data['fecha_vencimiento'] = $registro->fecha_vencimiento->format('Y-m-d');
        $data['responsable_id'] = (int) $registro->responsable_id;
        $data['ciclo_alertas'] = (int) $registro->ciclo_alertas;
        $data['copias'] = $registro->copias()->orderBy('tipo')->orderBy('email')->get(['tipo','email'])->toArray();
        return $data;
    }

    public static function version(Registro $registro): string
    {
        return hash_hmac('sha256', json_encode(self::snapshot($registro), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function edit(Registro $registro)
    {
        Gate::authorize('update', $registro);
        $responsables = User::where('role','responsable')->orWhere('id',$registro->responsable_id)
            ->orderBy('name')->get(['id','name','email','activo']);
        $copias = [];
        foreach (self::COPIAS as $tipo=>$label) {
            $copias[$tipo] = $registro->copias()->where('tipo',$tipo)->orderBy('email')->pluck('email')->implode('; ');
        }
        $version = self::version($registro);
        $historial = $registro->historial()->with('usuario:id,name')->latest('id')->paginate(10);
        return view('registros.edit', compact('registro','responsables','copias','version','historial'));
    }

    public function update(Request $request, Registro $registro)
    {
        Gate::authorize('update', $registro);
        $rules = [
            'pais'=>['required','string','max:100'], 'fabricante'=>['required','string','max:255'],
            'nombre_producto'=>['required','string','max:500'], 'rs_nso'=>['required','string','max:150'],
            'fecha_vencimiento'=>['required','date_format:Y-m-d'], 'grupo'=>['nullable','string','max:255'],
            'forma_farmaceutica'=>['nullable','string','max:255'], 'presentacion'=>['nullable','string','max:10000'],
            'estado_actual'=>['nullable','string','max:150'], 'tipo_elemento'=>['nullable','string','max:100'],
            'ruta_origen'=>['nullable','string','max:10000'], 'responsable_id'=>['required','integer',Rule::exists('users','id')],
            'version'=>['required','string','size:64'], 'copias'=>['required','array:'.implode(',',array_keys(self::COPIAS))],
        ];
        foreach (self::COPIAS as $tipo=>$label) $rules['copias.'.$tipo] = ['present','nullable','string','max:5000'];
        $data = $request->validate($rules);
        $copies = [];
        foreach (self::COPIAS as $tipo=>$label) {
            foreach (preg_split('/[;\r\n]+/', $data['copias'][$tipo] ?? '') as $email) {
                $email = strtolower(trim($email));
                if ($email === '') continue;
                if (strlen($email)>254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['copias.'.$tipo=>'Correo invalido en '.$label.': '.$email]);
                }
                $copies[$tipo.'|'.$email] = ['tipo'=>$tipo,'email'=>$email];
            }
        }
        $message = DB::transaction(function () use ($registro, $request, $data, $copies) {
            $current = Registro::whereKey($registro->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $current);
            if (!hash_equals(self::version($current), $data['version'])) {
                throw ValidationException::withMessages(['version'=>'Otro usuario modifico este registro. Recarga la pagina antes de guardar.']);
            }
            $responsable = User::whereKey($data['responsable_id'])->lockForUpdate()->firstOrFail();
            if ((int)$responsable->id !== (int)$current->responsable_id && ($responsable->role !== 'responsable' || !$responsable->activo)) {
                throw ValidationException::withMessages(['responsable_id'=>'Selecciona un responsable activo.']);
            }
            if ($current->alertas()->where('estado','procesando')->exists()) {
                throw ValidationException::withMessages(['registro'=>'Hay una alerta en procesamiento. Intenta guardar cuando termine.']);
            }
            $before = self::snapshot($current);
            $fechaCambio = $before['fecha_vencimiento'] !== $data['fecha_vencimiento'];
            $fields = array_intersect_key($data, self::CAMPOS);
            $fields['responsable_id'] = (int)$data['responsable_id'];
            $current->fill($fields);
            if ($fechaCambio) $current->ciclo_alertas++;
            $newCopies = collect(array_values($copies))->sortBy(fn($c)=>$c['tipo'].'|'.$c['email'])->values()->all();
            $oldCopies = collect($before['copias'])->sortBy(fn($c)=>$c['tipo'].'|'.$c['email'])->values()->all();
            if (!$current->isDirty() && $newCopies === $oldCopies) return 'No se detectaron cambios.';
            $current->save();
            if ($newCopies !== $oldCopies) {
                $current->copias()->delete();
                foreach ($newCopies as $copy) $current->copias()->create($copy);
            }
            if ($fechaCambio) {
                $current->alertas()->whereIn('estado',['pendiente','fallida'])->update([
                    'estado'=>'cancelada','proximo_intento_at'=>null,'bloqueada_at'=>null,'bloqueo_token'=>null,
                ]);
            } else {
                $cc = collect($newCopies)->pluck('email')->unique()->reject(fn($email)=>strcasecmp($email,$responsable->email)===0)->values()->all();
                $current->alertas()->where('ciclo_alertas',$current->ciclo_alertas)
                    ->whereIn('estado',['pendiente','fallida'])->get()->each(function ($alert) use ($responsable,$cc) {
                        $alert->update(['destinatario_email'=>$responsable->email,'copias_emails'=>$cc]);
                    });
            }
            $current->historial()->create([
                'usuario_id'=>$request->user()->id,'accion'=>'actualizacion',
                'valores_anteriores'=>$before,'valores_nuevos'=>self::snapshot($current),
            ]);
            $lost = !$request->user()->esAdministrador() && (int)$current->responsable_id !== (int)$request->user()->id;
            return $lost ? 'Registro reasignado. Ya no tienes acceso a este registro.' : 'Registro actualizado e historial guardado.';
        }, 3);
        return redirect()->route('registros.index')->with('status', $message);
    }
}
