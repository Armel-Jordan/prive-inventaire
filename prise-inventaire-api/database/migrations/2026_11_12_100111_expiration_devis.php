<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expiration des devis au choix (plan de parité fripv2, DE3) : réglages de l'entreprise.
 * - devis_expiration_bloquante (vrai par défaut, le comportement actuel) : un devis dont la validité est passée passe à
 *   « Expiré » et ne s'accepte plus ; faux : « Expiré » n'est qu'un repère, le devis reste envoyé et se décide toujours ;
 * - devis_validite_jours (30 par défaut) : durée de validité proposée pour un nouveau devis.
 *
 * Ajouts seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_parametres', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_parametres', 'devis_expiration_bloquante')) {
                $t->boolean('devis_expiration_bloquante')->default(true);
                $t->unsignedSmallInteger('devis_validite_jours')->default(30);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_parametres', fn (Blueprint $t) => $t->dropColumn(['devis_expiration_bloquante', 'devis_validite_jours']));
    }
};
