<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            // Changer l'enum pour retirer super_admin
            $table->enum('role', ['admin', 'manager', 'user'])->default('user')->change();
        });

        // Remettre tenant_id NOT NULL avec la foreign key
        DB::statement('ALTER TABLE admin_users MODIFY tenant_id BIGINT UNSIGNED NOT NULL');

        $fkExists = DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'admin_users'
              AND CONSTRAINT_NAME = 'admin_users_tenant_id_foreign'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");

        if (empty($fkExists)) {
            DB::statement('ALTER TABLE admin_users ADD CONSTRAINT admin_users_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'manager', 'user'])->default('user')->change();
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }
};
