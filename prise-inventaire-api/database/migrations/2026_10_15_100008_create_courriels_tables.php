<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courriels (plan de parité fripv2, phase 0.6) : paramètres d'envoi de chaque entreprise
 * (serveur de la plateforme ou SMTP propre) et modèles de courriel par type de document et
 * par langue. Tables neuves : rien d'existant n'est modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courriel_parametres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            // plateforme : serveur d'envoi de l'application ; smtp : serveur propre à l'entreprise.
            $table->string('transport', 20)->default('plateforme');
            $table->string('smtp_hote')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            // tls (STARTTLS), ssl (TLS implicite) ou aucun
            $table->string('smtp_chiffrement', 10)->nullable();
            $table->string('smtp_utilisateur')->nullable();
            // Chiffré par l'application (cast encrypted), jamais renvoyé par l'API.
            $table->text('smtp_mot_de_passe')->nullable();
            $table->string('expediteur_adresse')->nullable();
            $table->string('expediteur_nom')->nullable();
            $table->string('repondre_a')->nullable();
            $table->string('copie_cachee')->nullable();
            $table->text('signature')->nullable();
            $table->timestamps();
        });

        Schema::create('courriel_modeles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // devis, commande_client, facture, bon_livraison, commande_fournisseur, general
            $table->string('type', 30);
            $table->string('langue', 5);
            $table->string('objet');
            $table->text('contenu');
            // Gabarit du nom de la pièce jointe (variables admises) ; vide = nom par défaut.
            $table->string('nom_fichier')->nullable();
            $table->string('repondre_a')->nullable();
            $table->boolean('accuse_lecture')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'langue'], 'courriel_modeles_tenant_type_langue_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courriel_modeles');
        Schema::dropIfExists('courriel_parametres');
    }
};
