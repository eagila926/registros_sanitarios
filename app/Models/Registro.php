<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registro extends Model
{
    use SoftDeletes;

    protected $table = 'registros';

    protected $fillable = [
        'pais',
        'fabricante',
        'nombre_producto',
        'rs_nso',
        'fecha_vencimiento',
        'grupo',
        'forma_farmaceutica',
        'presentacion',
        'estado_actual',
        'responsable_id',
        'tipo_elemento',
        'ruta_origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'ciclo_alertas' => 'integer',
        ];
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function copias()
    {
        return $this->hasMany(RegistroCopia::class, 'registro_id');
    }

    public function historial()
    {
        return $this->hasMany(RegistroHistorial::class, 'registro_id');
    }

    public function alertas()
    {
        return $this->hasMany(RegistroAlerta::class, 'registro_id');
    }

    public function scopeVisiblesPara($query, User $usuario)
    {
        if (! $usuario->activo) {
            return $query->whereRaw('1 = 0');
        }

        if ($usuario->esAdministrador()) {
            return $query;
        }

        return $query->where('responsable_id', $usuario->id);
    }
}