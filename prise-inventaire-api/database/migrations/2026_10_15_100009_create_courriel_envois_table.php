<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File d'envoi et historique des courriels (plan de parité fripv2, phase 0.6) : chaque
 * courriel est enregistré tel qu'il part (destinataires, objet, corps), avec son statut,
 * ses tentatives et la dernière erreur. Table neuve : rien d'existant n'est modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courriel_envois', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 30);
            $table->unsignedBigInteger('document_id')->nullable();
            // Instantané du numéro : l'historique reste lisible même si le document change.
            $table->string('document_numero', 50)->nullable();
            $table->json('destinataires');
            $table->json('copies')->nullable();
            $table->json('copies_cachees')->nullable();
            $table->string('expediteur_adresse');
            $table->string('expediteur_nom')->nullable();
            $table->string('repondre_a')->nullable();
            $table->string('objet');
            $table->longText('corps_html');
            $table->longText('corps_texte');
            // Nom du PDF joint, régénéré depuis le document à chaque tentative.
            $table->string('piece_jointe')->nullable();
            $table->boolean('accuse_lecture')->default(false);
            // en_attente, en_cours, envoye, echec
            $table->string('statut', 20)->default('en_attente');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('dernier_essai')->nullable();
            $table->timestamp('envoye_le')->nullable();
            $table->text('erreur')->nullable();
            $table->unsignedBigInteger('envoye_par')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'document_id'], 'courriel_envois_document_index');
            $table->index(['statut', 'dernier_essai'], 'courriel_envois_file_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courriel_envois');
    }
};
