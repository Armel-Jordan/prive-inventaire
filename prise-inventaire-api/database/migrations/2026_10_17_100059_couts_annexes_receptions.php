<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coûts annexes d'une réception (plan de parité fripv2, phase 3.3 : « coûts annexes répartis ») : transport, douane,
 * assurance, manutention… payés pour faire venir la marchandise. Chacun se répartit sur les lignes de la réception
 * (au prorata de la valeur, de la quantité, du poids ou du volume) et s'ajoute au coût de revient de la marchandise
 * reçue, que la valorisation de l'inventaire retient.
 *
 * Ajouts seulement : table reception_frais ; reception_arrivages_ligne.frais_unitaire (coûts annexes par unité de
 * stock, dans la devise de l'entreprise).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reception_frais')) {
            Schema::create('reception_frais', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('reception_id')->constrained('receptions')->cascadeOnDelete();
                // transport, douane, assurance, manutention, autre
                $table->string('type', 30);
                $table->string('libelle', 120)->nullable();
                // Qui facture ce coût (transporteur, transitaire), et le numéro de sa facture.
                $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
                $table->string('reference', 60)->nullable();
                $table->decimal('montant', 14, 2);
                // Vide : devise de l'entreprise (taux 1).
                $table->string('devise', 10)->nullable();
                $table->decimal('taux_change', 14, 6)->default(1);
                // valeur, quantite, poids, volume
                $table->string('repartition', 20)->default('valeur');
                $table->timestamps();
            });
        }

        Schema::table('reception_arrivages_ligne', function (Blueprint $table) {
            if (! Schema::hasColumn('reception_arrivages_ligne', 'frais_unitaire')) {
                $table->decimal('frais_unitaire', 14, 4)->nullable()->after('cout_unitaire');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reception_arrivages_ligne', fn (Blueprint $table) => $table->dropColumn('frais_unitaire'));
        Schema::dropIfExists('reception_frais');
    }
};
