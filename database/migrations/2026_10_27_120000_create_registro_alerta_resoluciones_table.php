<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registro_alerta_resoluciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registro_alerta_id')
                ->constrained('registro_alertas')
                ->restrictOnDelete();

            $table->string('operador', 150);
            $table->string('accion', 30);
            $table->string('estado_anterior', 30);
            $table->string('estado_nuevo', 30);
            $table->text('evidencia');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['registro_alerta_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_alerta_resoluciones');
    }
};
