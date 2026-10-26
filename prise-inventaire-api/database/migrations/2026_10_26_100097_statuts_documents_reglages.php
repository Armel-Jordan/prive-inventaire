<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statuts des documents (plan de parité fripv2, « Statuts transactions ») : libellés et couleurs choisis par
 * l'entreprise pour les statuts système de ses documents. Sans réglage, ceux du catalogue App\Support\StatutsDocuments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statuts_documents_reglages')) {
            Schema::create('statuts_documents_reglages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('document', 40);
                $table->string('code', 40);
                $table->string('libelle_fr', 60)->nullable();
                $table->string('libelle_en', 60)->nullable();
                $table->string('couleur_fond', 7)->nullable();
                $table->string('couleur_texte', 7)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'document', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statuts_documents_reglages');
    }
};
