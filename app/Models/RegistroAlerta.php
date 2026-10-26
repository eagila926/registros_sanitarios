<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroAlerta extends Model
{
    protected $table = 'registro_alertas';

    protected $fillable = [
        'ciclo_alertas',
        'fecha_vencimiento',
        'tipo',
        'programada_para',
        'estado',
        'destinatario_email',
        'copias_emails',
        'intentos',
        'ultimo_intento_at',
        'proximo_intento_at',
        'bloqueada_at',
        'bloqueo_token',
        'enviada_at',
        'proveedor_message_id',
        'ultimo_error',
    ];

    protected function casts(): array
    {
        return [
            'ciclo_alertas' => 'integer',
            'fecha_vencimiento' => 'date',
            'programada_para' => 'datetime',
            'copias_emails' => 'array',
            'intentos' => 'integer',
            'ultimo_intento_at' => 'datetime',
            'proximo_intento_at' => 'datetime',
            'bloqueada_at' => 'datetime',
            'enviada_at' => 'datetime',
        ];
    }

    public function registro()
    {
        return $this->belongsTo(Registro::class, 'registro_id');
    }
}