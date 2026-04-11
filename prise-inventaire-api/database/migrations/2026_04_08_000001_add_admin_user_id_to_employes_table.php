<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employes', 'admin_user_id')) {
            return;
        }

        Schema::table('employes', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->foreign('admin_user_id')->references('id')->on('admin_users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->dropForeign(['admin_user_id']);
            $table->dropColumn('admin_user_id');
        });
    }
};
