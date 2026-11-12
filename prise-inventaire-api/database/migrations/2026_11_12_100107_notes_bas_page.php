<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes de bas de page des documents de vente (plan de parité fripv2, socle S4.4) : des textes par type de document et
 * par langue, une note par défaut ; le document retient la note choisie (vide : celle par défaut, 0 : aucune), et son
 * texte se fige avec son identité à l'envoi ou à l'émission.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['devis', 'com_client_entete', 'factures', 'bons_livraison'];

    public function up(): void
    {
        if (! Schema::hasTable('notes_bas_page')) {
            Schema::create('notes_bas_page', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('nom', 100);
                // devis, commande_client, facture, bon_livraison.
                $t->string('document', 30);
                // fr, en ; vide : toutes les langues.
                $t->string('langue', 2)->nullable();
                $t->text('contenu');
                $t->boolean('par_defaut')->default(false);
                $t->boolean('actif')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['tenant_id', 'document']);
            });
        }
        foreach (self::DOCUMENTS as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'note_bas_page_id')) {
                    $t->unsignedBigInteger('note_bas_page_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::DOCUMENTS as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'note_bas_page_id')) {
                    $t->dropColumn('note_bas_page_id');
                }
            });
        }
        Schema::dropIfExists('notes_bas_page');
    }
};
