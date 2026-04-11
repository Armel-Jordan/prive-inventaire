<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('com_four_ligne', 'unite_achat')) {
            return;
        }

        Schema::table('com_four_ligne', function (Blueprint $table) {
            $table->string('unite_achat', 30)->nullable()->after('quantite_commandee');
            $table->unsignedSmallInteger('qte_par_unite_achat')->default(1)->after('unite_achat');
        });
    }

    public function down(): void
    {
        Schema::table('com_four_ligne', function (Blueprint $table) {
            $table->dropColumn(['unite_achat', 'qte_par_unite_achat']);
        });
    }
};
