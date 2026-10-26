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
        Schema::create('registro_alertas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registro_id')
                ->constrained('registros')
                ->restrictOnDelete();

            $table->unsignedInteger('ciclo_alertas');
            $table->date('fecha_vencimiento');

            $table->enum('tipo', [
                'inicial',
                '90_dias',
                '60_dias',
                '30_dias',
            ]);

            $table->dateTime('programada_para');

            $table->enum('estado', [
                'pendiente',
                'procesando',
                'enviada',
                'fallida',
                'cancelada',
            ])->default('pendiente');

            $table->string('destinatario_email', 254);
            $table->json('copias_emails');

            $table->unsignedInteger('intentos')->default(0);
            $table->dateTime('ultimo_intento_at')->nullable();
            $table->dateTime('proximo_intento_at')->nullable();

            $table->dateTime('bloqueada_at')->nullable();
            $table->uuid('bloqueo_token')->nullable();

            $table->dateTime('enviada_at')->nullable();
            $table->string('proveedor_message_id', 255)->nullable();
            $table->text('ultimo_error')->nullable();

            $table->timestamps();

            $table->unique(
                ['registro_id', 'ciclo_alertas', 'tipo'],
                'uq_alerta_registro_ciclo_tipo'
            );

            $table->index(
                ['estado', 'programada_para'],
                'idx_alertas_programadas'
            );

            $table->index(
                ['estado', 'proximo_intento_at'],
                'idx_alertas_reintentos'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_alertas');
    }
};
