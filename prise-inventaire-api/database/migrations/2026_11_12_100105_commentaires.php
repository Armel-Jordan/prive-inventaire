<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commentaires des fiches et des documents (plan de parité fripv2, socle S4.6) : des messages signés et datés, et pour
 * chaque compte le dernier commentaire lu de chaque fiche (le compte des non-lus en découle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commentaires')) {
            Schema::create('commentaires', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                // Type de fiche ou de document (ceux des pièces jointes) et son identifiant.
                $t->string('entite', 40);
                $t->unsignedBigInteger('entite_id');
                $t->unsignedBigInteger('auteur_id')->nullable();
                $t->text('texte');
                $t->timestamps();
                $t->index(['tenant_id', 'entite', 'entite_id']);
            });
        }
        if (! Schema::hasTable('commentaire_lectures')) {
            Schema::create('commentaire_lectures', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('entite', 40);
                $t->unsignedBigInteger('entite_id');
                // Le plus grand identifiant de commentaire lu par ce compte sur cette fiche.
                $t->unsignedBigInteger('dernier_lu_id')->default(0);
                $t->timestamps();
                $t->unique(['user_id', 'entite', 'entite_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commentaire_lectures');
        Schema::dropIfExists('commentaires');
    }
};
