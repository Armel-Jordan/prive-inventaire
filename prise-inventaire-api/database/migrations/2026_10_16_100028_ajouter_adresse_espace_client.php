<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace client sur son propre site (et non plus une section /portail du back-office) : l'adresse de ce
 * site, transmise par le back-office de l'entreprise, sert aux liens des courriels (invitation, mot de
 * passe oublié). Une adresse envoyée par un visiteur anonyme n'est jamais reprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('portail_parametres', 'url_espace_client')) {
            return;
        }

        Schema::table('portail_parametres', function (Blueprint $table) {
            $table->string('url_espace_client', 255)->nullable()->after('categories');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('portail_parametres', 'url_espace_client')) {
            return;
        }

        Schema::table('portail_parametres', function (Blueprint $table) {
            $table->dropColumn('url_espace_client');
        });
    }
};
