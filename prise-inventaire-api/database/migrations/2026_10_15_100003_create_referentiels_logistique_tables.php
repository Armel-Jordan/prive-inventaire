<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels logistiques et commerciaux (plan de parité fripv2, phase 0.2) : tables
 * neuves, isolées par tenant. Les secteurs existants ne sont pas encore rattachés aux
 * entrepôts : ce sera la fonctionnalité « multi-entrepôts » (phase 2.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrepots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20);
            $table->string('nom', 100);
            // entrepot, magasin, vehicule, depot, autre
            $table->string('type', 20)->default('entrepot');
            $table->string('adresse', 255)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('code_postal', 20)->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('responsable', 150)->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('territoires', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('itineraires', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->foreignId('territoire_id')->nullable()->constrained('territoires')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('representants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20)->nullable();
            $table->string('nom', 150);
            $table->string('email', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->decimal('taux_commission', 5, 2)->nullable();
            $table->foreignId('territoire_id')->nullable()->constrained('territoires')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('transporteurs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20)->nullable();
            $table->string('nom', 150);
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            // Lien de suivi des colis, éventuellement avec {numero} à remplacer.
            $table->string('url_suivi', 255)->nullable();
            $table->string('numero_compte', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nom']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transporteurs');
        Schema::dropIfExists('representants');
        Schema::dropIfExists('itineraires');
        Schema::dropIfExists('territoires');
        Schema::dropIfExists('entrepots');
    }
};
