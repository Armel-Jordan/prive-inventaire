<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('slug', 50)->unique();
            $table->string('db_name', 100);
            $table->string('db_host', 100)->default('127.0.0.1');
            $table->string('db_port', 10)->default('3306');
            $table->string('db_username', 100)->default('root');
            $table->string('db_password', 255)->nullable();
            $table->boolean('actif')->default(true);
            $table->date('date_expiration')->nullable();
            $table->string('plan', 50)->default('basic');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
