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
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('renouvelable')->default(true)->after('date_expiration');
            $table->integer('duree_abonnement')->default(1)->after('renouvelable'); // 1, 3 ou 5 ans
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['renouvelable', 'duree_abonnement']);
        });
    }
};
