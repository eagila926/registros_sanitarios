<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroHistorial extends Model
{
    protected $table = 'registro_historial';

    // Esta tabla solo tiene created_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'accion',
        'valores_anteriores',
        'valores_nuevos',
    ];

    protected function casts(): array
    {
        return [
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function registro()
    {
        return $this->belongsTo(Registro::class, 'registro_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}