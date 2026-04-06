<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->foreignId('tenant_id')->nullable()->change()->constrained('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->foreignId('tenant_id')->nullable(false)->change()->constrained('tenants')->onDelete('cascade');
        });
    }
};
