<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports IA périodiques (plan de parité fripv2, phase 5.4) : un envoi programmé peut porter une question à l'IA ; à
 * chaque envoi, l'IA commente le rapport joint pour y répondre, et son analyse entre dans le courriel.
 *
 * Ajouts seulement : colonne facultative question_ia de rapports_programmes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rapports_programmes', 'question_ia')) {
            Schema::table('rapports_programmes', function (Blueprint $table) {
                $table->text('question_ia')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rapports_programmes', 'question_ia')) {
            Schema::table('rapports_programmes', fn (Blueprint $table) => $table->dropColumn('question_ia'));
        }
    }
};
