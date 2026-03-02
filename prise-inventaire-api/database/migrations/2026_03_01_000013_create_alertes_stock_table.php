<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('alertes_stock')) {
            Schema::create('alertes_stock', function (Blueprint $table) {
                $table->id();
                $table->string('produit_numero', 50);
                $table->string('produit_nom', 255)->nullable();
                $table->decimal('seuil_min', 15, 4)->default(0);
                $table->decimal('seuil_critique', 15, 4)->default(0);
                $table->boolean('actif')->default(true);
                $table->boolean('notification_email')->default(false);
                $table->timestamps();

                $table->unique('produit_numero');
                $table->index('actif');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_stock');
    }
};
