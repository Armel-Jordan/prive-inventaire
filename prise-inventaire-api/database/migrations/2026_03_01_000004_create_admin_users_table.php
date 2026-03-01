<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('nom', 100);
            $table->string('email', 100)->unique();
            $table->string('password', 255);
            $table->enum('role', ['super_admin', 'admin', 'manager', 'user'])->default('user');
            $table->boolean('actif')->default(true);
            $table->timestamp('derniere_connexion')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
