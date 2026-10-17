<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilisation automatique (plan de parité fripv2, phase 4.3) : les ventes, les achats et les paiements passent au
 * journal selon des réglages propres à chaque entreprise — journaux, comptes de contrepartie, correspondances par mode
 * de paiement, par taxe et par catégorie de produit, et date à partir de laquelle les documents se comptabilisent.
 *
 * Ajouts seulement : table parametres_comptables (une ligne par entreprise). Les écritures générées portent le type de
 * leur document en source et son identifiant en source_id.
 */
return new class extends Migration
{
    private const JOURNAUX = ['journal_ventes_id', 'journal_achats_id', 'journal_banque_id', 'journal_caisse_id', 'journal_od_id'];

    private const COMPTES = [
        'compte_clients_id', 'compte_fournisseurs_id', 'compte_ventes_id', 'compte_achats_id', 'compte_tva_collectee_id',
        'compte_tva_deductible_id', 'compte_caisse_id', 'compte_banque_id', 'compte_acomptes_id', 'compte_escomptes_id',
        'compte_cartes_cadeaux_id',
    ];

    public function up(): void
    {
        if (Schema::hasTable('parametres_comptables')) {
            return;
        }
        Schema::create('parametres_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            // Les documents datés à partir de ce jour se comptabilisent (vide : rien ne se comptabilise).
            $table->date('date_debut')->nullable();
            foreach (self::JOURNAUX as $colonne) {
                $table->foreignId($colonne)->nullable()->constrained('journaux_comptables')->nullOnDelete();
            }
            foreach (self::COMPTES as $colonne) {
                $table->foreignId($colonne)->nullable()->constrained('comptes_comptables')->nullOnDelete();
            }
            // modes : mode de paiement → caisse ou banque ; taxes : taxe → comptes collectée et déductible ;
            // categories : catégorie de produit → comptes de vente et d'achat.
            $table->json('correspondances')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres_comptables');
    }
};
