<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sécurité des comptes (plan de parité fripv2, phase 6.1) : réinitialisation du mot de passe par lien
 * envoyé par courriel, et double authentification par application (TOTP). Colonnes facultatives sur
 * admin_users (vides = double authentification désactivée, comportement actuel) et table neuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            // Secret et codes de secours chiffrés par le modèle (cast « encrypted »).
            $table->text('deux_facteurs_secret')->nullable();
            $table->timestamp('deux_facteurs_active_le')->nullable();
            $table->text('deux_facteurs_codes_secours')->nullable();
            // Dernier pas de 30 s accepté : un même code ne sert qu'une fois.
            $table->unsignedBigInteger('deux_facteurs_dernier_pas')->nullable();
        });

        // Les jetons sont stockés hachés (sha256) : la base ne contient jamais un lien utilisable.
        Schema::create('reinitialisations_mot_de_passe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('jeton', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reinitialisations_mot_de_passe');
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['deux_facteurs_secret', 'deux_facteurs_active_le', 'deux_facteurs_codes_secours', 'deux_facteurs_dernier_pas']);
        });
    }
};
