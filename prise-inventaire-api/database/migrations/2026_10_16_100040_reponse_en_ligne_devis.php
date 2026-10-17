<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponse en ligne aux devis (plan de parité fripv2, phase 1.3) : le courriel d'un devis porte un lien vers une page
 * du site de l'espace client où le client accepte ou refuse le devis.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devis', 'envoye_le')) {
            Schema::table('devis', function (Blueprint $table) {
                // Envoi au client : bouton Envoyer, courriel du devis ou lien de réponse partagé.
                $table->timestamp('envoye_le')->nullable()->after('statut');
                // Lien de réponse : jeton chiffré (pour refaire le lien) et son empreinte (pour retrouver le devis).
                $table->text('reponse_jeton')->nullable();
                $table->string('reponse_jeton_hash', 64)->nullable()->unique();
                // Réponse du client : donnée en ligne (nom, commentaire) ou notée par un employé.
                $table->timestamp('reponse_le')->nullable();
                $table->boolean('reponse_en_ligne')->default(false);
                $table->string('reponse_nom', 150)->nullable();
                $table->text('reponse_commentaire')->nullable();
            });
        }

        if (! Schema::hasColumn('courriel_parametres', 'devis_reponse_en_ligne')) {
            Schema::table('courriel_parametres', function (Blueprint $table) {
                // Boutons Accepter / Refuser dans les courriels des devis (activé par défaut).
                $table->boolean('devis_reponse_en_ligne')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devis', 'envoye_le')) {
            Schema::table('devis', function (Blueprint $table) {
                $table->dropUnique(['reponse_jeton_hash']);
                $table->dropColumn(['envoye_le', 'reponse_jeton', 'reponse_jeton_hash', 'reponse_le', 'reponse_en_ligne', 'reponse_nom', 'reponse_commentaire']);
            });
        }
        if (Schema::hasColumn('courriel_parametres', 'devis_reponse_en_ligne')) {
            Schema::table('courriel_parametres', function (Blueprint $table) {
                $table->dropColumn('devis_reponse_en_ligne');
            });
        }
    }
};
