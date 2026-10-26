<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RegistroCopia extends Model
{
    protected $table = 'registro_copias';
    protected $fillable = ['tipo', 'email'];
    public function registro()
    {
        return $this->belongsTo(Registro::class, 'registro_id');
    }
}
