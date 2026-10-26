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
        Schema::create('registro_copias', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registro_id')
                ->constrained('registros')
                ->restrictOnDelete();

            $table->enum('tipo', [
                'responsable2',
                'auditor',
                'observador1',
                'observador2',
                'observador3',
            ]);

            $table->string('email', 254);
            $table->timestamps();

            $table->unique(
                ['registro_id', 'tipo', 'email'],
                'uq_registro_copia'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_copias');
    }
};
