<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('registro_alertas', 'modo')) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->string('modo', 16)->default('log');
            });
        }

        // Crear primero el reemplazo para sostener la clave foránea.
        if (! Schema::hasIndex(
            'registro_alertas',
            'uq_alerta_registro_ciclo_tipo_modo'
        )) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->unique(
                    ['registro_id', 'ciclo_alertas', 'tipo', 'modo'],
                    'uq_alerta_registro_ciclo_tipo_modo'
                );
            });
        }

        if (Schema::hasIndex(
            'registro_alertas',
            'uq_alerta_registro_ciclo_tipo'
        )) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->dropUnique('uq_alerta_registro_ciclo_tipo');
            });
        }
    }

    public function down(): void
    {
        $hayDuplicados = DB::table('registro_alertas')
            ->select('registro_id', 'ciclo_alertas', 'tipo')
            ->groupBy('registro_id', 'ciclo_alertas', 'tipo')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hayDuplicados) {
            throw new RuntimeException(
                'Hay alertas en varios modos. No se puede revertir sin revisar los datos.'
            );
        }

        // También al revertir, crear primero el índice sustituto.
        if (! Schema::hasIndex(
            'registro_alertas',
            'uq_alerta_registro_ciclo_tipo'
        )) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->unique(
                    ['registro_id', 'ciclo_alertas', 'tipo'],
                    'uq_alerta_registro_ciclo_tipo'
                );
            });
        }

        if (Schema::hasIndex(
            'registro_alertas',
            'uq_alerta_registro_ciclo_tipo_modo'
        )) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->dropUnique('uq_alerta_registro_ciclo_tipo_modo');
            });
        }

        if (Schema::hasColumn('registro_alertas', 'modo')) {
            Schema::table('registro_alertas', function (Blueprint $table) {
                $table->dropColumn('modo');
            });
        }
    }
};