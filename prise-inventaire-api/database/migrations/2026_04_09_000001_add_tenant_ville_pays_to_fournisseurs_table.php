<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            if (! Schema::hasColumn('fournisseurs', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('fournisseurs', 'ville')) {
                $table->string('ville', 100)->nullable()->after('email');
            }
            if (! Schema::hasColumn('fournisseurs', 'pays')) {
                $table->string('pays', 100)->nullable()->after('ville');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'ville', 'pays']);
        });
    }
};
