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
        Schema::create('registros', function (Blueprint $table) {
            $table->id();

            $table->string('pais', 100);
            $table->string('fabricante', 255);
            $table->string('nombre_producto', 500);
            $table->string('rs_nso', 150);
            $table->date('fecha_vencimiento');

            $table->string('grupo', 255)->nullable();
            $table->string('forma_farmaceutica', 255)->nullable();
            $table->text('presentacion')->nullable();
            $table->string('estado_actual', 150)->nullable();

            $table->foreignId('responsable_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('tipo_elemento', 100)->nullable();
            $table->text('ruta_origen')->nullable();

            $table->unsignedInteger('ciclo_alertas')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['responsable_id', 'deleted_at', 'fecha_vencimiento'],
                'idx_registros_responsable'
            );

            $table->index(
                ['deleted_at', 'fecha_vencimiento'],
                'idx_registros_vencimiento'
            );

            $table->index(
                ['pais', 'rs_nso'],
                'idx_registros_pais_rs'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros');
    }
};
