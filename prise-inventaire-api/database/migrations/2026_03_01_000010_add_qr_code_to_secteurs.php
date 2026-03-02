<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secteurs', function (Blueprint $table) {
            $table->string('qr_code', 100)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('secteurs', function (Blueprint $table) {
            $table->dropColumn('qr_code');
        });
    }
};
