<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colis des bons de livraison (plan de parité fripv2, phase 1.6 : « Préparation boîte ») : la marchandise d'un bon
 * se range dans des colis numérotés d'après le bon (BL-00005-001), chacun avec ses lignes et quantités, son poids,
 * son étiquette ; le chargement du camion les coche.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bl_colis')) {
            Schema::create('bl_colis', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('bon_livraison_id')->constrained('bons_livraison')->cascadeOnDelete();
                $table->string('numero', 40);
                $table->decimal('poids_kg', 12, 3)->nullable();
                $table->string('notes', 500)->nullable();
                $table->foreignId('prepare_par')->nullable()->constrained('admin_users')->nullOnDelete();
                // Chargement du camion : colis coché comme chargé.
                $table->timestamp('charge_le')->nullable();
                $table->foreignId('charge_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['tenant_id', 'numero']);
            });
        }
        if (! Schema::hasTable('bl_colis_lignes')) {
            Schema::create('bl_colis_lignes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('colis_id')->constrained('bl_colis')->cascadeOnDelete();
                $table->foreignId('bon_livraison_ligne_id')->constrained('bon_livraison_lignes')->cascadeOnDelete();
                $table->unsignedInteger('quantite');
                $table->timestamps();
                $table->unique(['colis_id', 'bon_livraison_ligne_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bl_colis_lignes');
        Schema::dropIfExists('bl_colis');
    }
};
