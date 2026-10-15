<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels de base (plan de parité fripv2, phase 0.1) : tables neuves, isolées par
 * tenant, sans toucher aux tables existantes. Les fiches (produits, factures…) ne s'y
 * branchent pas encore : ce sera fait fonctionnalité par fonctionnalité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20);
            $table->string('libelle', 100);
            $table->string('symbole', 20)->nullable();
            $table->unsignedTinyInteger('decimales')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('unite_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // 1 unité source = facteur × unité cible.
            $table->foreignId('unite_source_id')->constrained('unites');
            $table->foreignId('unite_cible_id')->constrained('unites');
            $table->decimal('facteur', 18, 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'unite_source_id', 'unite_cible_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30)->nullable();
            $table->string('nom', 100);
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('fabricants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30)->nullable();
            $table->string('nom', 150);
            $table->string('site_web', 255)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nom']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('modes_paiement', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30);
            $table->string('libelle', 100);
            // especes, cheque, virement, carte, mobile_money, prelevement, autre
            $table->string('type', 30)->default('autre');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('conditions_paiement', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->unsignedSmallInteger('delai_jours')->default(0);
            $table->boolean('fin_de_mois')->default(false);
            $table->decimal('escompte_pourcentage', 5, 2)->nullable();
            $table->unsignedSmallInteger('escompte_jours')->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('comptes_bancaires', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('nom', 100);
            $table->string('banque', 150);
            $table->string('agence', 150)->nullable();
            $table->string('titulaire', 150)->nullable();
            $table->string('numero_compte', 50)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('devise_code', 3)->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->boolean('actif')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nom']);
        });

        Schema::create('devises', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 3);
            $table->string('libelle', 100);
            $table->string('symbole', 10)->nullable();
            $table->unsignedTinyInteger('decimales')->default(2);
            // Valeur d'une unité de cette devise, exprimée dans la devise de référence du tenant.
            $table->decimal('taux', 18, 8)->default(1);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('devise_taux', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('devise_id')->constrained('devises')->cascadeOnDelete();
            $table->decimal('taux', 18, 8);
            $table->date('date_effet');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['devise_id', 'date_effet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devise_taux');
        Schema::dropIfExists('devises');
        Schema::dropIfExists('comptes_bancaires');
        Schema::dropIfExists('conditions_paiement');
        Schema::dropIfExists('modes_paiement');
        Schema::dropIfExists('fabricants');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('unite_conversions');
        Schema::dropIfExists('unites');
    }
};
