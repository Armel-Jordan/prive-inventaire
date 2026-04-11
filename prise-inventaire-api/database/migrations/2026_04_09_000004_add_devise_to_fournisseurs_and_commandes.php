<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fournisseurs', 'devise')) {
            Schema::table('fournisseurs', function (Blueprint $table) {
                $table->string('devise', 10)->default('EUR')->after('actif');
            });
        }

        if (! Schema::hasColumn('com_four_entete', 'devise')) {
            Schema::table('com_four_entete', function (Blueprint $table) {
                $table->string('devise', 10)->default('EUR')->after('montant_total');
                $table->decimal('taux_change', 10, 6)->default(1.000000)->after('devise');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn('devise');
        });
        Schema::table('com_four_entete', function (Blueprint $table) {
            $table->dropColumn(['devise', 'taux_change']);
        });
    }
};
