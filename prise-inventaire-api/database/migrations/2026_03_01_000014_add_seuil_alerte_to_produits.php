<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('produits', 'seuil_alerte')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->decimal('seuil_alerte', 15, 4)->nullable()->after('prix_unitaire');
            });
        }
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('seuil_alerte');
        });
    }
};
