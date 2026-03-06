<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->foreignId('fournisseur_principal_id')->nullable()->after('seuil_alerte')->constrained('fournisseurs')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropForeign(['fournisseur_principal_id']);
            $table->dropColumn('fournisseur_principal_id');
        });
    }
};
