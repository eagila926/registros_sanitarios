<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('registro_historial', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registro_id')
                ->constrained('registros')
                ->restrictOnDelete();

            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('accion', [
                'creacion',
                'importacion',
                'actualizacion',
                'eliminacion',
                'restauracion',
            ]);

            $table->json('valores_anteriores')->nullable();
            $table->json('valores_nuevos')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['registro_id', 'created_at'],
                'idx_historial_registro_fecha'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_historial');
    }
};
