<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portail client (plan de parité fripv2, phase 1.11) : les clients d'une entreprise commandent, suivent leurs
 * commandes et leurs factures et gèrent leur profil. Comptes et sessions propres au portail, sans lien avec
 * les comptes des employés. Le portail est désactivé tant que l'entreprise ne l'ouvre pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portail_parametres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->boolean('actif')->default(false);
            $table->text('message_accueil')->nullable();
            // Catégories de produits proposées au catalogue ; null : tous les produits actifs.
            $table->json('categories')->nullable();
            $table->timestamps();
        });

        Schema::create('portail_comptes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('email', 255);
            $table->string('telephone', 50)->nullable();
            // Choisi par le client depuis le lien d'invitation : vide tant que l'invitation n'est pas acceptée.
            $table->string('password')->nullable();
            $table->boolean('actif')->default(true);
            // Lien d'invitation ou de nouveau mot de passe (empreinte SHA-256), à usage unique.
            $table->string('invitation_jeton', 64)->nullable()->unique();
            $table->dateTime('invitation_expire_le')->nullable();
            $table->dateTime('derniere_connexion')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('portail_jetons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portail_compte_id')->constrained('portail_comptes')->cascadeOnDelete();
            // Empreinte SHA-256 du jeton de session remis au navigateur.
            $table->string('jeton', 64)->unique();
            $table->dateTime('expire_le');
            $table->dateTime('derniere_utilisation')->nullable();
            $table->timestamps();
        });

        // Commandes passées depuis le portail : le compte qui l'a passée (colonne ajoutée, nulle ailleurs).
        Schema::table('com_client_entete', function (Blueprint $table) {
            $table->unsignedBigInteger('portail_compte_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('com_client_entete', function (Blueprint $table) {
            $table->dropIndex(['portail_compte_id']);
            $table->dropColumn('portail_compte_id');
        });
        Schema::dropIfExists('portail_jetons');
        Schema::dropIfExists('portail_comptes');
        Schema::dropIfExists('portail_parametres');
    }
};
